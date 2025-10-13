<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\AssetMapper\Compiler;

use Psr\Log\LoggerInterface;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\Exception\CircularAssetsException;
use Symfony\Component\AssetMapper\Exception\RuntimeException;
use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;
use Symfony\Component\AssetMapper\ImportMap\JavaScriptImport;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\Filesystem\Path;
use Peast\Peast;
use Peast\Syntax\Node\ImportDeclaration;
use Peast\Syntax\Node\ImportExpression;
use Peast\Syntax\Node\StringLiteral;
use Peast\Syntax\Node\TemplateLiteral;
use Peast\Syntax\Node\TemplateElement;

/**
 * Resolves import paths in JS files.
 *
 * @author Ryan Weaver <ryan@symfonycasts.com>
 */
final class JavaScriptImportPathCompiler implements AssetCompilerInterface
{

    public function __construct(
        private readonly ImportMapConfigReader $importMapConfigReader,
        private readonly string $missingImportMode = self::MISSING_IMPORT_WARN,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    public function compile(string $content, MappedAsset $asset, AssetMapperInterface $assetMapper): string
    {
        $replacements = [];

        try {
            $ast = Peast::latest($content, ['sourceType' => Peast::SOURCE_TYPE_MODULE])->parse();
        } catch (\Throwable $e) {
            throw new RuntimeException(\sprintf('Failed to parse JavaScript in "%s". Error: "%s".', $asset->sourcePath, $e->getMessage()), 0, $e);
        }

        // Helper to find exact literal bounds in original content around an approximate index
        $findBounds = static function (string $src, int $approxStart, string $raw) : ?array {
            $len = \strlen($raw);
            $startScan = max(0, $approxStart - 16);
            $endScan = min(\strlen($src), $approxStart + 16 + $len);
            $window = substr($src, $startScan, $endScan - $startScan);
            $pos = strpos($window, $raw);
            if ($pos === false) {
                return null;
            }
            $absStart = $startScan + $pos;
            return [$absStart, $absStart + $len];
        };

        $ast->traverse(function ($node) use (&$replacements, $asset, $assetMapper, $content, $findBounds) {
            if (!$node instanceof ImportDeclaration && !$node instanceof ImportExpression) {
                return;
            }

            $source = $node->getSource();

            if ($source instanceof StringLiteral) {
                $newValue = $this->processImport($source->getValue(), $node instanceof ImportExpression, $asset, $assetMapper);
                if (null !== $newValue) {
                    $raw = $source->getRaw();
                    $bounds = $findBounds($content, $source->getLocation()->getStart()->getIndex(), $raw);
                    if ($bounds) {
                        $newLiteral = new StringLiteral();
                        $newLiteral->setRaw($raw);
                        $newLiteral->setValue($newValue);
                        $replacements[] = [$bounds[0], $bounds[1], $newLiteral->getRaw()];
                    }
                }
            } elseif ($source instanceof TemplateLiteral) {
                $parts = $source->getParts();
                if (count($parts) === 1 && $parts[0] instanceof TemplateElement) {
                    $rawInside = $parts[0]->getRawValue();
                    $importedModule = $rawInside;
                    $newValue = $this->processImport($importedModule, true, $asset, $assetMapper);
                    if (null !== $newValue) {
                        $fullRaw = '`' . $rawInside . '`';
                        $bounds = $findBounds($content, $source->getLocation()->getStart()->getIndex(), $fullRaw);
                        if ($bounds) {
                            $replacements[] = [$bounds[0] + 1, $bounds[1] - 1, $newValue];
                        }
                    }
                }
            }
        });

        if (!$replacements) {
            return $content;
        }

        usort($replacements, function ($a, $b) { return $b[0] <=> $a[0]; });
        foreach ($replacements as [$start, $end, $replacement]) {
            $content = substr($content, 0, $start) . $replacement . substr($content, $end);
        }

        return $content;
    }

    public function supports(MappedAsset $asset): bool
    {
        return 'js' === $asset->publicExtension;
    }

    private function processImport(string $importedModule, bool $isLazy, MappedAsset $asset, AssetMapperInterface $assetMapper): ?string
    {
        if (str_starts_with($importedModule, '/')) {
            return null;
        }

        $isRelativeImport = str_starts_with($importedModule, '.');
        if (!$isRelativeImport) {
            $dependentAsset = $this->findAssetForBareImport($importedModule, $assetMapper);
        } else {
            $dependentAsset = $this->findAssetForRelativeImport($importedModule, $asset, $assetMapper);
        }

        if (!$dependentAsset) {
            return null;
        }

        if ($dependentAsset->logicalPath === $asset->logicalPath) {
            return null;
        }

        $addToImportMap = $isRelativeImport;
        $asset->addJavaScriptImport(new JavaScriptImport(
            $addToImportMap ? $dependentAsset->publicPathWithoutDigest : $importedModule,
            $dependentAsset->logicalPath,
            $dependentAsset->sourcePath,
            $isLazy,
            $addToImportMap,
        ));

        if (!$addToImportMap) {
            return null;
        }

        $relativeImportPath = Path::makeRelative($dependentAsset->publicPathWithoutDigest, \dirname($asset->publicPathWithoutDigest));
        return $this->makeRelativeForJavaScript($relativeImportPath);
    }

    private function makeRelativeForJavaScript(string $path): string
    {
        if (str_starts_with($path, '../')) {
            return $path;
        }

        return './'.$path;
    }

    private function handleMissingImport(string $message, ?\Throwable $e = null): void
    {
        match ($this->missingImportMode) {
            AssetCompilerInterface::MISSING_IMPORT_IGNORE => null,
            AssetCompilerInterface::MISSING_IMPORT_WARN => $this->logger?->warning($message),
            AssetCompilerInterface::MISSING_IMPORT_STRICT => throw new RuntimeException($message, 0, $e),
        };
    }

    private function findAssetForBareImport(string $importedModule, AssetMapperInterface $assetMapper): ?MappedAsset
    {
        if (!$importMapEntry = $this->importMapConfigReader->findRootImportMapEntry($importedModule)) {
            // don't warn on missing non-relative (bare) imports: these could be valid URLs

            return null;
        }

        try {
            if ($asset = $assetMapper->getAsset($importMapEntry->path)) {
                return $asset;
            }

            return $assetMapper->getAssetFromSourcePath($this->importMapConfigReader->convertPathToFilesystemPath($importMapEntry->path));
        } catch (CircularAssetsException $exception) {
            return $exception->getIncompleteMappedAsset();
        }
    }

    private function findAssetForRelativeImport(string $importedModule, MappedAsset $asset, AssetMapperInterface $assetMapper): ?MappedAsset
    {
        try {
            $resolvedSourcePath = Path::join(\dirname($asset->sourcePath), $importedModule);
        } catch (RuntimeException $e) {
            // avoid warning about vendor imports - these are often comments
            if (!$asset->isVendor) {
                $this->handleMissingImport(\sprintf('Error processing import in "%s": ', $asset->sourcePath).$e->getMessage(), $e);
            }

            return null;
        }

        try {
            $dependentAsset = $assetMapper->getAssetFromSourcePath($resolvedSourcePath);
        } catch (CircularAssetsException $exception) {
            $dependentAsset = $exception->getIncompleteMappedAsset();
        }

        if ($dependentAsset) {
            return $dependentAsset;
        }

        // avoid warning about vendor imports - these are often comments
        if ($asset->isVendor) {
            return null;
        }

        $message = \sprintf('Unable to find asset "%s" imported from "%s".', $importedModule, $asset->sourcePath);

        if (is_file($resolvedSourcePath)) {
            $message .= \sprintf('The file "%s" exists, but it is not in a mapped asset path. Add it to the "paths" config.', $resolvedSourcePath);
        } else {
            try {
                if (null !== $assetMapper->getAssetFromSourcePath(\sprintf('%s.js', $resolvedSourcePath))) {
                    $message .= \sprintf(' Try adding ".js" to the end of the import - i.e. "%s.js".', $importedModule);
                }
            } catch (CircularAssetsException) {
                // avoid circular error if there is self-referencing import comments
            }
        }

        $this->handleMissingImport($message);

        return null;
    }
}
