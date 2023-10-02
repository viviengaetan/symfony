<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\AssetMapper\Tests\ImportMap;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\AssetMapper\AssetMapperInterface;
use Symfony\Component\AssetMapper\ImportMap\ImportMapConfigReader;
use Symfony\Component\AssetMapper\ImportMap\ImportMapEntries;
use Symfony\Component\AssetMapper\ImportMap\ImportMapEntry;
use Symfony\Component\AssetMapper\ImportMap\ImportMapManager;
use Symfony\Component\AssetMapper\ImportMap\ImportMapType;
use Symfony\Component\AssetMapper\ImportMap\JavaScriptImport;
use Symfony\Component\AssetMapper\ImportMap\PackageRequireOptions;
use Symfony\Component\AssetMapper\ImportMap\Resolver\PackageResolverInterface;
use Symfony\Component\AssetMapper\ImportMap\Resolver\ResolvedImportMapPackage;
use Symfony\Component\AssetMapper\MappedAsset;
use Symfony\Component\AssetMapper\Path\PublicAssetsPathResolverInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

class ImportMapManagerTest extends TestCase
{
    private AssetMapperInterface&MockObject $assetMapper;
    private PublicAssetsPathResolverInterface&MockObject $pathResolver;
    private PackageResolverInterface&MockObject $packageResolver;
    private ImportMapConfigReader&MockObject $configReader;
    private HttpClientInterface&MockObject $httpClient;
    private ImportMapManager $importMapManager;

    private Filesystem $filesystem;
    private static string $writableRoot = __DIR__.'/../fixtures/importmaps_for_writing';

    protected function setUp(): void
    {
        $this->filesystem = new Filesystem();
        if (!file_exists(__DIR__.'/../fixtures/importmaps_for_writing')) {
            $this->filesystem->mkdir(self::$writableRoot);
        }
        if (!file_exists(__DIR__.'/../fixtures/importmaps_for_writing/assets')) {
            $this->filesystem->mkdir(self::$writableRoot.'/assets');
        }
    }

    protected function tearDown(): void
    {
        $this->filesystem->remove(self::$writableRoot);
    }

    /**
     * @dataProvider getRawImportMapDataTests
     */
    public function testGetRawImportMapData(array $importMapEntries, array $mappedAssets, array $expectedData)
    {
        $manager = $this->createImportMapManager();
        $this->mockImportMap($importMapEntries);
        $this->mockAssetMapper($mappedAssets);
        $this->configReader->expects($this->any())
            ->method('getRootDirectory')
            ->willReturn('/fake/root');

        $this->assertEquals($expectedData, $manager->getRawImportMapData());
    }

    public function getRawImportMapDataTests(): iterable
    {
        yield 'it returns simple remote entry' => [
            [
                new ImportMapEntry(
                    '@hotwired/stimulus',
                    url: 'https://anyurl.com/stimulus'
                ),
            ],
            [],
            [
                '@hotwired/stimulus' => [
                    'path' => 'https://anyurl.com/stimulus',
                    'type' => 'js',
                ],
            ],
        ];

        yield 'it sets path to local path when remote package is downloaded' => [
            [
                new ImportMapEntry(
                    '@hotwired/stimulus',
                    path: 'vendor/stimulus.js',
                    url: 'https://anyurl.com/stimulus',
                    isDownloaded: true,
                ),
            ],
            [
                new MappedAsset(
                    'vendor/stimulus.js',
                    publicPath: '/assets/vendor/stimulus.js',
                ),
            ],
            [
                '@hotwired/stimulus' => [
                    'path' => '/assets/vendor/stimulus.js',
                    'type' => 'js',
                ],
            ],
        ];

        yield 'it returns basic local javascript file' => [
            [
                new ImportMapEntry(
                    'app',
                    path: 'app.js',
                ),
            ],
            [
                new MappedAsset(
                    'app.js',
                    publicPath: '/assets/app.js',
                ),
            ],
            [
                'app' => [
                    'path' => '/assets/app.js',
                    'type' => 'js',
                ],
            ],
        ];

        yield 'it returns basic local css file' => [
            [
                new ImportMapEntry(
                    'app.css',
                    path: 'styles/app.css',
                    type: ImportMapType::CSS,
                ),
            ],
            [
                new MappedAsset(
                    'styles/app.css',
                    publicPath: '/assets/styles/app.css',
                ),
            ],
            [
                'app.css' => [
                    'path' => '/assets/styles/app.css',
                    'type' => 'css',
                ],
            ],
        ];

        $simpleAsset = new MappedAsset(
            'simple.js',
            publicPathWithoutDigest: '/assets/simple.js',
            publicPath: '/assets/simple-d1g3st.js',
        );
        yield 'it adds dependency to the importmap' => [
            [
                new ImportMapEntry(
                    'app',
                    path: 'app.js',
                ),
            ],
            [
                new MappedAsset(
                    'app.js',
                    publicPath: '/assets/app.js',
                    javaScriptImports: [new JavaScriptImport('/assets/simple.js', isLazy: false, asset: $simpleAsset, addImplicitlyToImportMap: true)]
                ),
                $simpleAsset,
            ],
            [
                'app' => [
                    'path' => '/assets/app.js',
                    'type' => 'js',
                ],
                '/assets/simple.js' => [
                    'path' => '/assets/simple-d1g3st.js',
                    'type' => 'js',
                ],
            ],
        ];

        $eagerImportsSimpleAsset = new MappedAsset(
            'imports_simple.js',
            publicPathWithoutDigest: '/assets/imports_simple.js',
            publicPath: '/assets/imports_simple-d1g3st.js',
            javaScriptImports: [new JavaScriptImport('/assets/simple.js', isLazy: false, asset: $simpleAsset, addImplicitlyToImportMap: true)]
        );
        yield 'it processes imports recursively' => [
            [
                new ImportMapEntry(
                    'app',
                    path: 'app.js',
                ),
            ],
            [
                new MappedAsset(
                    'app.js',
                    publicPath: '/assets/app.js',
                    javaScriptImports: [new JavaScriptImport('/assets/imports_simple.js', isLazy: true, asset: $eagerImportsSimpleAsset, addImplicitlyToImportMap: true)]
                ),
                $eagerImportsSimpleAsset,
                $simpleAsset,
            ],
            [
                'app' => [
                    'path' => '/assets/app.js',
                    'type' => 'js',
                ],
                '/assets/imports_simple.js' => [
                    'path' => '/assets/imports_simple-d1g3st.js',
                    'type' => 'js',
                ],
                '/assets/simple.js' => [
                    'path' => '/assets/simple-d1g3st.js',
                    'type' => 'js',
                ],
            ],
        ];

        yield 'it process can skip adding one importmap entry but still add a child' => [
            [
                new ImportMapEntry(
                    'app',
                    path: 'app.js',
                ),
                new ImportMapEntry(
                    'imports_simple',
                    path: 'imports_simple.js',
                ),
            ],
            [
                new MappedAsset(
                    'app.js',
                    publicPath: '/assets/app.js',
                    javaScriptImports: [new JavaScriptImport('imports_simple', isLazy: true, asset: $eagerImportsSimpleAsset, addImplicitlyToImportMap: false)]
                ),
                $eagerImportsSimpleAsset,
                $simpleAsset,
            ],
            [
                'app' => [
                    'path' => '/assets/app.js',
                    'type' => 'js',
                ],
                '/assets/simple.js' => [
                    'path' => '/assets/simple-d1g3st.js',
                    'type' => 'js',
                ],
                'imports_simple' => [
                    'path' => '/assets/imports_simple-d1g3st.js',
                    'type' => 'js',
                ],
            ],
        ];

        yield 'imports with a module name are not added to the importmap' => [
            [
                new ImportMapEntry(
                    'app',
                    path: 'app.js',
                ),
            ],
            [
                new MappedAsset(
                    'app.js',
                    publicPath: '/assets/app.js',
                    javaScriptImports: [new JavaScriptImport('simple', isLazy: false, asset: $simpleAsset)]
                ),
                $simpleAsset,
            ],
            [
                'app' => [
                    'path' => '/assets/app.js',
                    'type' => 'js',
                ],
            ],
        ];

        yield 'it does not process dependencies of CSS files' => [
            [
                new ImportMapEntry(
                    'app.css',
                    path: 'app.css',
                    type: ImportMapType::CSS,
                ),
            ],
            [
                new MappedAsset(
                    'app.css',
                    publicPath: '/assets/app.css',
                    javaScriptImports: [new JavaScriptImport('/assets/simple.js', asset: $simpleAsset)]
                ),
            ],
            [
                'app.css' => [
                    'path' => '/assets/app.css',
                    'type' => 'css',
                ],
            ],
        ];

        yield 'it handles a relative path file' => [
            [
                new ImportMapEntry(
                    'app',
                    path: './assets/app.js',
                ),
            ],
            [
                new MappedAsset(
                    'app.js',
                    // /fake/root is the mocked root directory
                    '/fake/root/assets/app.js',
                    publicPath: '/assets/app.js',
                ),
            ],
            [
                'app' => [
                    'path' => '/assets/app.js',
                    'type' => 'js',
                ],
            ],
        ];

        yield 'it handles an absolute path file' => [
            [
                new ImportMapEntry(
                    'app',
                    path: '/some/path/assets/app.js',
                ),
            ],
            [
                new MappedAsset(
                    'app.js',
                    '/some/path/assets/app.js',
                    publicPath: '/assets/app.js',
                ),
            ],
            [
                'app' => [
                    'path' => '/assets/app.js',
                    'type' => 'js',
                ],
            ],
        ];
    }

    public function testGetRawImportDataUsesCacheFile()
    {
        $manager = $this->createImportMapManager();
        $importmapData = [
            'app' => [
                'path' => 'app.js',
                'entrypoint' => true,
            ],
            '@hotwired/stimulus' => [
                'path' => 'https://anyurl.com/stimulus',
            ],
        ];
        $this->writeFile('public/assets/importmap.json', json_encode($importmapData));
        $this->pathResolver->expects($this->once())
            ->method('getPublicFilesystemPath')
            ->willReturn(self::$writableRoot.'/public/assets');

        $this->assertEquals($importmapData, $manager->getRawImportMapData());
    }

    /**
     * @dataProvider getEntrypointMetadataTests
     */
    public function testGetEntrypointMetadata(MappedAsset $entryAsset, array $expected)
    {
        $manager = $this->createImportMapManager();
        $this->mockAssetMapper([$entryAsset]);
        // put the entry asset in the importmap
        $this->mockImportMap([
            new ImportMapEntry('the_entrypoint_name', path: $entryAsset->logicalPath, isEntrypoint: true),
        ]);

        $this->assertEquals($expected, $manager->getEntrypointMetadata('the_entrypoint_name'));
    }

    public function getEntrypointMetadataTests(): iterable
    {
        yield 'an entry with no dependencies' => [
            new MappedAsset(
                'app.js',
                publicPath: '/assets/app.js',
            ),
            [],
        ];

        $simpleAsset = new MappedAsset(
            'simple.js',
            publicPathWithoutDigest: '/assets/simple.js',
        );
        yield 'an entry with a non-lazy dependency is included' => [
            new MappedAsset(
                'app.js',
                publicPath: '/assets/app.js',
                javaScriptImports: [new JavaScriptImport('/assets/simple.js', isLazy: false, asset: $simpleAsset)]
            ),
            ['/assets/simple.js'], // path is the key in the importmap
        ];

        yield 'an entry with a non-lazy dependency with module name is included' => [
            new MappedAsset(
                'app.js',
                publicPath: '/assets/app.js',
                javaScriptImports: [new JavaScriptImport('simple', isLazy: false, asset: $simpleAsset)]
            ),
            ['simple'], // path is the key in the importmap
        ];

        yield 'an entry with a lazy dependency is not included' => [
            new MappedAsset(
                'app.js',
                publicPath: '/assets/app.js',
                javaScriptImports: [new JavaScriptImport('/assets/simple.js', isLazy: true, asset: $simpleAsset)]
            ),
            [],
        ];

        $importsSimpleAsset = new MappedAsset(
            'imports_simple.js',
            publicPathWithoutDigest: '/assets/imports_simple.js',
            javaScriptImports: [new JavaScriptImport('/assets/simple.js', isLazy: false, asset: $simpleAsset)]
        );
        yield 'an entry follows through dependencies recursively' => [
            new MappedAsset(
                'app.js',
                publicPath: '/assets/app.js',
                javaScriptImports: [new JavaScriptImport('/assets/imports_simple.js', isLazy: false, asset: $importsSimpleAsset)]
            ),
            ['/assets/imports_simple.js', '/assets/simple.js'],
        ];
    }

    public function testGetEntrypointMetadataUsesCacheFile()
    {
        $manager = $this->createImportMapManager();
        $entrypointData = [
            'app',
            '/assets/foo.js',
        ];
        $this->writeFile('public/assets/entrypoint.foo.json', json_encode($entrypointData));
        $this->pathResolver->expects($this->once())
            ->method('getPublicFilesystemPath')
            ->willReturn(self::$writableRoot.'/public/assets');

        $this->assertEquals($entrypointData, $manager->getEntrypointMetadata('foo'));
    }

    public function testGetImportMapData()
    {
        $manager = $this->createImportMapManager();
        $this->mockImportMap([
            new ImportMapEntry(
                'entry1',
                path: 'entry1.js',
                isEntrypoint: true,
            ),
            new ImportMapEntry(
                'entry2',
                path: 'entry2.js',
                isEntrypoint: true,
            ),
            new ImportMapEntry(
                'normal_js_file',
                path: 'normal_js_file.js',
            ),
            new ImportMapEntry(
                'css_in_importmap',
                path: 'styles/css_in_importmap.css',
                type: ImportMapType::CSS,
            ),
            new ImportMapEntry(
                'never_imported_css',
                path: 'styles/never_imported_css.css',
                type: ImportMapType::CSS,
            ),
        ]);

        $importedFile1 = new MappedAsset(
            'imported_file1.js',
            publicPathWithoutDigest: '/assets/imported_file1.js',
            publicPath: '/assets/imported_file1-d1g35t.js',
        );
        $importedFile2 = new MappedAsset(
            'imported_file2.js',
            publicPathWithoutDigest: '/assets/imported_file2.js',
            publicPath: '/assets/imported_file2-d1g35t.js',
        );
        $normalJsFile = new MappedAsset(
            'normal_js_file.js',
            publicPathWithoutDigest: '/assets/normal_js_file.js',
            publicPath: '/assets/normal_js_file-d1g35t.js',
        );
        $importedCss1 = new MappedAsset(
            'styles/file1.css',
            publicPathWithoutDigest: '/assets/styles/file1.css',
            publicPath: '/assets/styles/file1-d1g35t.css',
        );
        $importedCss2 = new MappedAsset(
            'styles/file2.css',
            publicPathWithoutDigest: '/assets/styles/file2.css',
            publicPath: '/assets/styles/file2-d1g35t.css',
        );
        $importedCssInImportmap = new MappedAsset(
            'styles/css_in_importmap.css',
            publicPathWithoutDigest: '/assets/styles/css_in_importmap.css',
            publicPath: '/assets/styles/css_in_importmap-d1g35t.css',
        );
        $neverImportedCss = new MappedAsset(
            'styles/never_imported_css.css',
            publicPathWithoutDigest: '/assets/styles/never_imported_css.css',
            publicPath: '/assets/styles/never_imported_css-d1g35t.css',
        );
        $this->mockAssetMapper([
            new MappedAsset(
                'entry1.js',
                publicPath: '/assets/entry1-d1g35t.js',
                javaScriptImports: [
                    new JavaScriptImport('/assets/imported_file1.js', isLazy: false, asset: $importedFile1, addImplicitlyToImportMap: true),
                    new JavaScriptImport('/assets/styles/file1.css', isLazy: false, asset: $importedCss1, addImplicitlyToImportMap: true),
                    new JavaScriptImport('normal_js_file', isLazy: false, asset: $normalJsFile),
                ]
            ),
            new MappedAsset(
                'entry2.js',
                publicPath: '/assets/entry2-d1g35t.js',
                javaScriptImports: [
                    new JavaScriptImport('/assets/imported_file2.js', isLazy: false, asset: $importedFile2, addImplicitlyToImportMap: true),
                    new JavaScriptImport('css_in_importmap', isLazy: false, asset: $importedCssInImportmap),
                    new JavaScriptImport('/assets/styles/file2.css', isLazy: false, asset: $importedCss2, addImplicitlyToImportMap: true),
                ]
            ),
            $importedFile1,
            $importedFile2,
            $normalJsFile,
            $importedCss1,
            $importedCss2,
            $importedCssInImportmap,
            $neverImportedCss,
        ]);

        $actualImportMapData = $manager->getImportMapData(['entry2', 'entry1']);

        $this->assertEquals([
            'entry1' => [
                'path' => '/assets/entry1-d1g35t.js',
                'type' => 'js',
            ],
            '/assets/imported_file1.js' => [
                'path' => '/assets/imported_file1-d1g35t.js',
                'type' => 'js',
                'preload' => true,
            ],
            'entry2' => [
                'path' => '/assets/entry2-d1g35t.js',
                'type' => 'js',
            ],
            '/assets/imported_file2.js' => [
                'path' => '/assets/imported_file2-d1g35t.js',
                'type' => 'js',
                'preload' => true,
            ],
            'normal_js_file' => [
                'path' => '/assets/normal_js_file-d1g35t.js',
                'type' => 'js',
                'preload' => true, // preloaded as it's a non-lazy dependency of an entry
            ],
            '/assets/styles/file1.css' => [
                'path' => '/assets/styles/file1-d1g35t.css',
                'type' => 'css',
                'preload' => true,
            ],
            '/assets/styles/file2.css' => [
                'path' => '/assets/styles/file2-d1g35t.css',
                'type' => 'css',
                'preload' => true,
            ],
            'css_in_importmap' => [
                'path' => '/assets/styles/css_in_importmap-d1g35t.css',
                'type' => 'css',
                'preload' => true,
            ],
            'never_imported_css' => [
                'path' => '/assets/styles/never_imported_css-d1g35t.css',
                'type' => 'css',
            ],
        ], $actualImportMapData);

        // now check the order
        $this->assertEquals([
            // entry2 & its dependencies
            'entry2',
            '/assets/imported_file2.js',
            'css_in_importmap', // in the importmap, but brought earlier because it's a dependency of entry2
            '/assets/styles/file2.css',

            // entry1 & its dependencies
            'entry1',
            '/assets/imported_file1.js',
            '/assets/styles/file1.css',
            'normal_js_file',

            // importmap entries never imported
            'never_imported_css',
        ], array_keys($actualImportMapData));
    }

    public function testFindRootImportMapEntry()
    {
        $manager = $this->createImportMapManager();
        $entry1 = new ImportMapEntry('entry1', isEntrypoint: true);
        $this->mockImportMap([$entry1]);

        $this->assertSame($entry1, $manager->findRootImportMapEntry('entry1'));
        $this->assertNull($manager->findRootImportMapEntry('entry2'));
    }

    public function testGetEntrypointNames()
    {
        $manager = $this->createImportMapManager();
        $this->mockImportMap([
            new ImportMapEntry('entry1', isEntrypoint: true),
            new ImportMapEntry('entry2', isEntrypoint: true),
            new ImportMapEntry('not_entrypoint'),
        ]);

        $this->assertEquals(['entry1', 'entry2'], $manager->getEntrypointNames());
    }

    /**
     * @dataProvider getRequirePackageTests
     */
    public function testRequire(array $packages, int $expectedProviderPackageArgumentCount, array $resolvedPackages, array $expectedImportMap, array $expectedDownloadedFiles)
    {
        $manager = $this->createImportMapManager();
        // physical file we point to in one test
        $this->writeFile('assets/some_file.js', 'some file contents');

        // make it so that downloaded files are found in AssetMapper
        $this->assetMapper->expects($this->any())
            ->method('getAssetFromSourcePath')
            ->willReturnCallback(function (string $sourcePath) use ($expectedDownloadedFiles) {
                foreach ($expectedDownloadedFiles as $file => $contents) {
                    $expectedPath = self::$writableRoot.'/assets/vendor/'.$file;
                    if (realpath($expectedPath) === realpath($sourcePath)) {
                        return new MappedAsset('vendor/'.$file, $sourcePath);
                    }
                }

                if (str_ends_with($sourcePath, 'some_file.js')) {
                    // physical file we point to in one test
                    return new MappedAsset('some_file.js', $sourcePath);
                }

                return null;
            })
        ;

        $this->configReader->expects($this->any())
            ->method('getRootDirectory')
            ->willReturn(self::$writableRoot);
        $this->configReader->expects($this->once())
            ->method('getEntries')
            ->willReturn(new ImportMapEntries())
        ;
        $this->configReader->expects($this->once())
            ->method('writeEntries')
            ->with($this->callback(function (ImportMapEntries $entries) use ($expectedImportMap) {
                // assert the $entries look as expected
                $this->assertCount(\count($expectedImportMap), $entries);
                $simplifiedEntries = [];
                foreach ($entries as $entry) {
                    $simplifiedEntries[$entry->importName] = [
                        'url' => $entry->url,
                        ($entry->isDownloaded ? 'downloaded_to' : 'path') => $entry->path,
                        'type' => $entry->type->value,
                        'entrypoint' => $entry->isEntrypoint,
                    ];
                }

                $this->assertSame(array_keys($expectedImportMap), array_keys($simplifiedEntries));
                foreach ($expectedImportMap as $name => $expectedData) {
                    foreach ($expectedData as $key => $val) {
                        $this->assertSame($val, $simplifiedEntries[$name][$key]);
                    }
                }

                return true;
            }))
        ;

        $this->packageResolver->expects($this->exactly(0 === $expectedProviderPackageArgumentCount ? 0 : 1))
            ->method('resolvePackages')
            ->with($this->callback(function (array $packages) use ($expectedProviderPackageArgumentCount) {
                return \count($packages) === $expectedProviderPackageArgumentCount;
            }))
            ->willReturn($resolvedPackages)
        ;

        $manager->require($packages);
        foreach ($expectedDownloadedFiles as $file => $expectedContents) {
            $this->assertFileExists(self::$writableRoot.'/assets/vendor/'.$file);
            $actualContents = file_get_contents(self::$writableRoot.'/assets/vendor/'.$file);
            $this->assertSame($expectedContents, $actualContents);
        }
    }

    public static function getRequirePackageTests(): iterable
    {
        yield 'require single lodash package' => [
            'packages' => [new PackageRequireOptions('lodash')],
            'expectedProviderPackageArgumentCount' => 1,
            'resolvedPackages' => [
                self::resolvedPackage('lodash', 'https://ga.jspm.io/npm:lodash@1.2.3/lodash.js'),
            ],
            'expectedImportMap' => [
                'lodash' => [
                    'url' => 'https://ga.jspm.io/npm:lodash@1.2.3/lodash.js',
                ],
            ],
            'expectedDownloadedFiles' => [],
        ];

        yield 'require two packages' => [
            'packages' => [new PackageRequireOptions('lodash'), new PackageRequireOptions('cowsay')],
            'expectedProviderPackageArgumentCount' => 2,
            'resolvedPackages' => [
                self::resolvedPackage('lodash', 'https://ga.jspm.io/npm:lodash@1.2.3/lodash.js'),
                self::resolvedPackage('cowsay', 'https://ga.jspm.io/npm:cowsay@4.5.6/cowsay.js'),
            ],
            'expectedImportMap' => [
                'lodash' => [
                    'url' => 'https://ga.jspm.io/npm:lodash@1.2.3/lodash.js',
                ],
                'cowsay' => [
                    'url' => 'https://ga.jspm.io/npm:cowsay@4.5.6/cowsay.js',
                ],
            ],
            'expectedDownloadedFiles' => [],
        ];

        yield 'single_package_that_returns_as_two' => [
            'packages' => [new PackageRequireOptions('lodash')],
            'expectedProviderPackageArgumentCount' => 1,
            'resolvedPackages' => [
                self::resolvedPackage('lodash', 'https://ga.jspm.io/npm:lodash@1.2.3/lodash.js'),
                self::resolvedPackage('lodash-dependency', 'https://ga.jspm.io/npm:lodash-dependency@9.8.7/lodash-dependency.js'),
            ],
            'expectedImportMap' => [
                'lodash' => [
                    'url' => 'https://ga.jspm.io/npm:lodash@1.2.3/lodash.js',
                ],
                'lodash-dependency' => [
                    'url' => 'https://ga.jspm.io/npm:lodash-dependency@9.8.7/lodash-dependency.js',
                ],
            ],
            'expectedDownloadedFiles' => [],
        ];

        yield 'single_package_with_version_constraint' => [
            'packages' => [new PackageRequireOptions('lodash', '^1.2.3')],
            'expectedProviderPackageArgumentCount' => 1,
            'resolvedPackages' => [
                self::resolvedPackage('lodash', 'https://ga.jspm.io/npm:lodash@1.2.7/lodash.js'),
            ],
            'expectedImportMap' => [
                'lodash' => [
                    'url' => 'https://ga.jspm.io/npm:lodash@1.2.7/lodash.js',
                ],
            ],
            'expectedDownloadedFiles' => [],
        ];

        yield 'single_package_that_downloads' => [
            'packages' => [new PackageRequireOptions('lodash', download: true)],
            'expectedProviderPackageArgumentCount' => 1,
            'resolvedPackages' => [
                self::resolvedPackage('lodash', 'https://ga.jspm.io/npm:lodash@1.2.3/lodash.js', download: true, content: 'the code in lodash.js'),
            ],
            'expectedImportMap' => [
                'lodash' => [
                    'url' => 'https://ga.jspm.io/npm:lodash@1.2.3/lodash.js',
                    'downloaded_to' => 'vendor/lodash.js',
                ],
            ],
            'expectedDownloadedFiles' => [
                'lodash.js' => 'the code in lodash.js',
            ],
        ];

        yield 'single_package_that_downloads_a_css_file' => [
            'packages' => [new PackageRequireOptions('bootstrap/dist/css/bootstrap.min.css', download: true)],
            'expectedProviderPackageArgumentCount' => 1,
            'resolvedPackages' => [
                self::resolvedPackage('bootstrap/dist/css/bootstrap.min.css', 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css', download: true, content: 'some sweet CSS'),
            ],
            'expectedImportMap' => [
                'bootstrap/dist/css/bootstrap.min.css' => [
                    'url' => 'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
                    'downloaded_to' => 'vendor/bootstrap/dist/css/bootstrap.min.css',
                    'type' => 'css',
                ],
            ],
            'expectedDownloadedFiles' => [
                'bootstrap/dist/css/bootstrap.min.css' => 'some sweet CSS',
            ],
        ];

        yield 'single_package_with_custom_import_name' => [
            'packages' => [new PackageRequireOptions('lodash', importName: 'lodash-es')],
            'expectedProviderPackageArgumentCount' => 1,
            'resolvedPackages' => [
                self::resolvedPackage('lodash', 'https://ga.jspm.io/npm:lodash@1.2.3/lodash.js', importName: 'lodash-es'),
            ],
            'expectedImportMap' => [
                'lodash-es' => [
                    'url' => 'https://ga.jspm.io/npm:lodash@1.2.3/lodash.js',
                ],
            ],
            'expectedDownloadedFiles' => [],
        ];

        yield 'single_package_with_a_path' => [
            'packages' => [new PackageRequireOptions('some/module', path: self::$writableRoot.'/assets/some_file.js')],
            'expectedProviderPackageArgumentCount' => 0,
            'resolvedPackages' => [],
            'expectedImportMap' => [
                'some/module' => [
                    // converted to relative path
                    'path' => './assets/some_file.js',
                ],
            ],
            'expectedDownloadedFiles' => [],
        ];
    }

    public function testRemove()
    {
        $manager = $this->createImportMapManager();
        $this->mockImportMap([
            new ImportMapEntry('lodash', url: 'https://ga.jspm.io/npm:lodash@1.2.3/lodash.js'),
            new ImportMapEntry('cowsay', path: 'vendor/moo.js', url: 'https://ga.jspm.io/npm:cowsay@4.5.6/cowsay.umd.js', isDownloaded: true),
            new ImportMapEntry('chance', path: 'vendor/chance.js', url: 'https://ga.jspm.io/npm:chance@7.8.9/build/chance.js', isDownloaded: true),
            new ImportMapEntry('app', path: 'app.js'),
            new ImportMapEntry('other', path: 'other.js'),
        ]);

        $this->mockAssetMapper([
            new MappedAsset('vendor/moo.js', self::$writableRoot.'/assets/vendor/moo.js'),
            new MappedAsset('app.js', self::$writableRoot.'/assets/app.js'),
        ]);

        $this->filesystem->mkdir(self::$writableRoot.'/assets/vendor');
        touch(self::$writableRoot.'/assets/vendor/moo.js');
        touch(self::$writableRoot.'/assets/vendor/chance.js');
        touch(self::$writableRoot.'/assets/app.js');
        touch(self::$writableRoot.'/assets/other.js');

        $this->configReader->expects($this->once())
            ->method('writeEntries')
            ->with($this->callback(function (ImportMapEntries $entries) {
                $this->assertCount(3, $entries);
                $this->assertTrue($entries->has('lodash'));
                $this->assertTrue($entries->has('chance'));
                $this->assertTrue($entries->has('other'));

                return true;
            }))
        ;

        $manager->remove(['cowsay', 'app']);
        $this->assertFileDoesNotExist(self::$writableRoot.'/assets/vendor/moo.js');
        $this->assertFileDoesNotExist(self::$writableRoot.'/assets/app.js');
        $this->assertFileExists(self::$writableRoot.'/assets/vendor/chance.js');
        $this->assertFileExists(self::$writableRoot.'/assets/other.js');
    }

    public function testUpdateAll()
    {
        $manager = $this->createImportMapManager();
        $this->mockImportMap([
            new ImportMapEntry('lodash', url: 'https://ga.jspm.io/npm:lodash@1.2.3/lodash.js'),
            new ImportMapEntry('cowsay', path: 'vendor/moo.js', url: 'https://ga.jspm.io/npm:cowsay@4.5.6/cowsay.umd.js', isDownloaded: true),
            new ImportMapEntry('bootstrap', url: 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.esm.js'),
            new ImportMapEntry('app', path: 'app.js'),
        ]);

        $this->mockAssetMapper([
            new MappedAsset('vendor/moo.js', self::$writableRoot.'/assets/vendor/moo.js'),
        ], false);
        $this->assetMapper->expects($this->any())
            ->method('getAssetFromSourcePath')
            ->willReturnCallback(function (string $sourcePath) {
                if (str_ends_with($sourcePath, 'assets/vendor/cowsay.js')) {
                    return new MappedAsset('vendor/cowsay.js');
                }

                return null;
            })
        ;

        $this->filesystem->mkdir(self::$writableRoot.'/assets/vendor');
        file_put_contents(self::$writableRoot.'/assets/vendor/moo.js', 'moo.js contents');
        file_put_contents(self::$writableRoot.'/assets/app.js', 'app.js contents');

        $this->packageResolver->expects($this->once())
            ->method('resolvePackages')
            ->with($this->callback(function ($packages) {
                $this->assertInstanceOf(PackageRequireOptions::class, $packages[0]);
                /* @var PackageRequireOptions[] $packages */
                $this->assertCount(3, $packages);

                $this->assertSame('lodash', $packages[0]->packageName);
                $this->assertFalse($packages[0]->download);

                $this->assertSame('cowsay', $packages[1]->packageName);
                $this->assertTrue($packages[1]->download);

                $this->assertSame('bootstrap', $packages[2]->packageName);

                return true;
            }))
            ->willReturn([
                self::resolvedPackage('lodash', 'https://ga.jspm.io/npm:lodash@1.2.9/lodash.js'),
                self::resolvedPackage('cowsay', 'https://ga.jspm.io/npm:cowsay@4.5.9/cowsay.umd.js', download: true, content: 'contents of cowsay.js'),
                self::resolvedPackage('bootstrap', 'https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.esm.js'),
            ])
        ;

        $this->configReader->expects($this->once())
            ->method('writeEntries')
            ->with($this->callback(function (ImportMapEntries $entries) {
                $this->assertCount(4, $entries);
                $this->assertTrue($entries->has('lodash'));
                $this->assertTrue($entries->has('cowsay'));
                $this->assertTrue($entries->has('bootstrap'));
                $this->assertTrue($entries->has('app'));

                $this->assertSame('https://ga.jspm.io/npm:lodash@1.2.9/lodash.js', $entries->get('lodash')->url);
                $this->assertSame('https://ga.jspm.io/npm:cowsay@4.5.9/cowsay.umd.js', $entries->get('cowsay')->url);
                $this->assertSame('https://cdn.jsdelivr.net/npm/bootstrap@5.2.3/dist/js/bootstrap.esm.js', $entries->get('bootstrap')->url);

                return true;
            }))
        ;

        $manager->update();
        $this->assertFileDoesNotExist(self::$writableRoot.'/assets/vendor/moo.js');
        $this->assertFileExists(self::$writableRoot.'/assets/vendor/cowsay.js');
        $actualContents = file_get_contents(self::$writableRoot.'/assets/vendor/cowsay.js');
        $this->assertSame('contents of cowsay.js', $actualContents);
    }

    public function testUpdateWithSpecificPackages()
    {
        $manager = $this->createImportMapManager();
        $this->mockImportMap([
            new ImportMapEntry('lodash', url: 'https://ga.jspm.io/npm:lodash@1.2.3/lodash.js'),
            new ImportMapEntry('cowsay', path: 'vendor/cowsay.js', url: 'https://ga.jspm.io/npm:cowsay@4.5.6/cowsay.umd.js', isDownloaded: true),
            new ImportMapEntry('bootstrap', url: 'https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.esm.js'),
            new ImportMapEntry('app', path: 'app.js'),
        ]);

        $this->writeFile('assets/vendor/cowsay.js', 'cowsay.js original contents');

        $this->packageResolver->expects($this->once())
            ->method('resolvePackages')
            ->willReturn([
                self::resolvedPackage('cowsay', 'https://ga.jspm.io/npm:cowsay@4.5.9/cowsay.umd.js', download: true, content: 'updated contents of cowsay.js'),
            ])
        ;

        $this->configReader->expects($this->any())
            ->method('getRootDirectory')
            ->willReturn(self::$writableRoot);
        $this->configReader->expects($this->once())
            ->method('writeEntries')
            ->with($this->callback(function (ImportMapEntries $entries) {
                $this->assertCount(4, $entries);

                $this->assertSame('https://ga.jspm.io/npm:lodash@1.2.3/lodash.js', $entries->get('lodash')->url);
                $this->assertSame('https://ga.jspm.io/npm:cowsay@4.5.9/cowsay.umd.js', $entries->get('cowsay')->url);

                return true;
            }))
        ;

        $this->mockAssetMapper([
            new MappedAsset('vendor/cowsay.js', self::$writableRoot.'/assets/vendor/cowsay.js'),
        ]);

        $manager->update(['cowsay']);
        $actualContents = file_get_contents(self::$writableRoot.'/assets/vendor/cowsay.js');
        $this->assertSame('updated contents of cowsay.js', $actualContents);
    }

    public function testDownloadMissingPackages()
    {
        $manager = $this->createImportMapManager();
        $this->mockImportMap([
            new ImportMapEntry('@hotwired/stimulus', path: 'vendor/@hotwired/stimulus.js', url: 'https://cdn.jsdelivr.net/npm/stimulus@3.2.1/+esm', isDownloaded: true),
            new ImportMapEntry('lodash', path: 'vendor/lodash.js', url: 'https://ga.jspm.io/npm:lodash@4.17.21/lodash.js', isDownloaded: true),
        ]);

        $this->mockAssetMapper([
            // fake that vendor/lodash.js exists, but not stimulus
            new MappedAsset('vendor/lodash.js'),
        ], false);
        $this->assetMapper->expects($this->any())
            ->method('getAssetFromSourcePath')
            ->willReturnCallback(function (string $sourcePath) {
                if (str_ends_with($sourcePath, 'assets/vendor/@hotwired/stimulus.js')) {
                    return new MappedAsset('vendor/@hotwired/stimulus.js');
                }
            })
        ;

        $response = $this->createMock(ResponseInterface::class);
        $response->expects($this->once())
            ->method('getContent')
            ->willReturn('contents of stimulus.js');

        $this->httpClient->expects($this->once())
            ->method('request')
            ->willReturn($response);

        $downloadedPackages = $manager->downloadMissingPackages();
        $this->assertCount(1, $downloadedPackages);

        $expectedDownloadedFiles = [
            '' => 'contents of stimulus.js',
        ];
        $downloadPath = self::$writableRoot.'/assets/vendor/@hotwired/stimulus.js';
        $this->assertFileExists($downloadPath);
        $this->assertSame('contents of stimulus.js', file_get_contents($downloadPath));
    }

    /**
     * @dataProvider getPackageNameTests
     */
    public function testParsePackageName(string $packageName, array $expectedReturn)
    {
        $parsed = ImportMapManager::parsePackageName($packageName);
        $this->assertIsArray($parsed);

        // remove integer keys - they're noise
        $parsed = array_filter($parsed, fn ($key) => !\is_int($key), \ARRAY_FILTER_USE_KEY);
        $this->assertEquals($expectedReturn, $parsed);

        $parsedWithAlias = ImportMapManager::parsePackageName($packageName.'=some_alias');
        $this->assertIsArray($parsedWithAlias);
        $parsedWithAlias = array_filter($parsedWithAlias, fn ($key) => !\is_int($key), \ARRAY_FILTER_USE_KEY);
        $expectedReturnWithAlias = $expectedReturn + ['alias' => 'some_alias'];
        $this->assertEquals($expectedReturnWithAlias, $parsedWithAlias, 'Asserting with alias');
    }

    public static function getPackageNameTests(): iterable
    {
        yield 'simple' => [
            'lodash',
            [
                'package' => 'lodash',
                'registry' => '',
            ],
        ];

        yield 'with_version_constraint' => [
            'lodash@^1.2.3',
            [
                'package' => 'lodash',
                'registry' => '',
                'version' => '^1.2.3',
            ],
        ];

        yield 'with_registry' => [
            'npm:lodash',
            [
                'package' => 'lodash',
                'registry' => 'npm',
            ],
        ];

        yield 'with_registry_and_version' => [
            'npm:lodash@^1.2.3',
            [
                'package' => 'lodash',
                'registry' => 'npm',
                'version' => '^1.2.3',
            ],
        ];

        yield 'namespaced_package_simple' => [
            '@hotwired/stimulus',
            [
                'package' => '@hotwired/stimulus',
                'registry' => '',
            ],
        ];

        yield 'namespaced_package_with_version_constraint' => [
            '@hotwired/stimulus@^1.2.3',
            [
                'package' => '@hotwired/stimulus',
                'registry' => '',
                'version' => '^1.2.3',
            ],
        ];

        yield 'namespaced_package_with_registry_no_version' => [
            'npm:@hotwired/stimulus',
            [
                'package' => '@hotwired/stimulus',
                'registry' => 'npm',
            ],
        ];

        yield 'namespaced_package_with_registry_and_version' => [
            'npm:@hotwired/stimulus@^1.2.3',
            [
                'package' => '@hotwired/stimulus',
                'registry' => 'npm',
                'version' => '^1.2.3',
            ],
        ];
    }

    private function createImportMapManager(): ImportMapManager
    {
        $this->pathResolver = $this->createMock(PublicAssetsPathResolverInterface::class);
        $this->assetMapper = $this->createMock(AssetMapperInterface::class);
        $this->configReader = $this->createMock(ImportMapConfigReader::class);
        $this->packageResolver = $this->createMock(PackageResolverInterface::class);
        $this->httpClient = $this->createMock(HttpClientInterface::class);

        return $this->importMapManager = new ImportMapManager(
            $this->assetMapper,
            $this->pathResolver,
            $this->configReader,
            self::$writableRoot.'/assets/vendor',
            $this->packageResolver,
            $this->httpClient,
        );
    }

    private static function resolvedPackage(string $packageName, string $url, bool $download = false, string $importName = null, string $content = null)
    {
        return new ResolvedImportMapPackage(
            new PackageRequireOptions($packageName, download: $download, importName: $importName),
            $url,
            $content,
        );
    }

    private function mockImportMap(array $importMapEntries): void
    {
        $this->configReader->expects($this->any())
            ->method('getEntries')
            ->willReturn(new ImportMapEntries($importMapEntries))
        ;
    }

    /**
     * @param MappedAsset[] $mappedAssets
     */
    private function mockAssetMapper(array $mappedAssets, bool $mockGetAssetFromSourcePath = true): void
    {
        $this->assetMapper->expects($this->any())
            ->method('getAsset')
            ->willReturnCallback(function (string $logicalPath) use ($mappedAssets) {
                foreach ($mappedAssets as $asset) {
                    if ($asset->logicalPath === $logicalPath) {
                        return $asset;
                    }
                }

                return null;
            })
        ;

        if (!$mockGetAssetFromSourcePath) {
            return;
        }

        $this->assetMapper->expects($this->any())
            ->method('getAssetFromSourcePath')
            ->willReturnCallback(function (string $sourcePath) use ($mappedAssets) {
                // collapse ../ in paths and ./ in paths to mimic the realpath AssetMapper uses
                $unCollapsePath = function (string $path) {
                    $parts = explode('/', $path);
                    $newParts = [];
                    foreach ($parts as $part) {
                        if ('..' === $part) {
                            array_pop($newParts);

                            continue;
                        }

                        if ('.' !== $part) {
                            $newParts[] = $part;
                        }
                    }

                    return implode('/', $newParts);
                };

                $sourcePath = $unCollapsePath($sourcePath);

                foreach ($mappedAssets as $asset) {
                    if (isset($asset->sourcePath) && $unCollapsePath($asset->sourcePath) === $sourcePath) {
                        return $asset;
                    }
                }

                return null;
            })
        ;
    }

    private function writeFile(string $filename, string $content): void
    {
        $path = \dirname(self::$writableRoot.'/'.$filename);
        if (!is_dir($path)) {
            mkdir($path, 0777, true);
        }
        file_put_contents(self::$writableRoot.'/'.$filename, $content);
    }
}