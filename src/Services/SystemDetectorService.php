<?php

namespace ZPMPackages\SshManager\Services;

use ZPMPackages\SshManager\Enums\OperatingSystem;

class SystemDetectorService
{
    public static function detect(): OperatingSystem
    {
        $family = PHP_OS_FAMILY;

        return match (true) {
            stripos($family, 'Windows') !== false => OperatingSystem::WINDOWS,
            stripos($family, 'Darwin') !== false  => OperatingSystem::MACOS,
            stripos($family, 'Linux') !== false   => self::detectLinuxVariant(),
            default                               => OperatingSystem::LINUX,
        };
    }

    protected static function detectLinuxVariant(): OperatingSystem
    {
        $uname = php_uname();

        if (stripos($uname, 'android') !== false) {
            return OperatingSystem::ANDROID;
        }

        return OperatingSystem::LINUX;
    }
}
