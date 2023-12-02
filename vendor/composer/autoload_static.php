<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInit633ffeafc1aa13e3a7348205d4120ef0
{
    public static $prefixLengthsPsr4 = array (
        's' => 
        array (
            'splitbrain\\PHPArchive\\' => 22,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'splitbrain\\PHPArchive\\' => 
        array (
            0 => __DIR__ . '/..' . '/splitbrain/php-archive/src',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInit633ffeafc1aa13e3a7348205d4120ef0::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInit633ffeafc1aa13e3a7348205d4120ef0::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInit633ffeafc1aa13e3a7348205d4120ef0::$classMap;

        }, null, ClassLoader::class);
    }
}
