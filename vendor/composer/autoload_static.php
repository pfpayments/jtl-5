<?php

// autoload_static.php @generated by Composer

namespace Composer\Autoload;

class ComposerStaticInitaeeb03a11bea7d310a0d49bda6a53ced
{
    public static $prefixLengthsPsr4 = array (
        'W' => 
        array (
            'WalleePayment\\' => 14,
        ),
        'P' => 
        array (
            'PostFinanceCheckout\\Sdk\\' => 24,
        ),
    );

    public static $prefixDirsPsr4 = array (
        'WalleePayment\\' => 
        array (
            0 => __DIR__ . '/../..' . '/Wallee',
        ),
        'PostFinanceCheckout\\Sdk\\' => 
        array (
            0 => __DIR__ . '/..' . '/postfinancecheckout/sdk/lib',
        ),
    );

    public static $classMap = array (
        'Composer\\InstalledVersions' => __DIR__ . '/..' . '/composer/InstalledVersions.php',
    );

    public static function getInitializer(ClassLoader $loader)
    {
        return \Closure::bind(function () use ($loader) {
            $loader->prefixLengthsPsr4 = ComposerStaticInitaeeb03a11bea7d310a0d49bda6a53ced::$prefixLengthsPsr4;
            $loader->prefixDirsPsr4 = ComposerStaticInitaeeb03a11bea7d310a0d49bda6a53ced::$prefixDirsPsr4;
            $loader->classMap = ComposerStaticInitaeeb03a11bea7d310a0d49bda6a53ced::$classMap;

        }, null, ClassLoader::class);
    }
}
