<?php

namespace ZPMPackages\SshManager\Enums;

enum OperatingSystem: string
{
    case LINUX = 'linux';
    case WINDOWS = 'windows';
    case MACOS = 'macos';
    case ANDROID = 'android';
}
