<?php

declare(strict_types=1);

namespace Arcturus\ModpackManager\Modpacks\Providers;

/** SHA-256 and exact sizes measured from the publisher's HTTPS server ZIPs, not publisher-issued digests. */
final class GtnhArchivePins
{
    public const FILES = [
        'GT_New_Horizons_2.8.1_Server_Java_8.zip' => [
            'sha256' => '3a3ec911f3b6703ec540682bb66873fc110e2b2c7f365431cf4d3f206cfec89b', 'size' => 426713118,
        ],
        'GT_New_Horizons_2.8.1_Server_Java_17-25.zip' => [
            'sha256' => 'c8353689543e36913ef1901b6e7626df75778b821295a5641a8cf3312a3c9f7f', 'size' => 444127778,
        ],
        'GT_New_Horizons_2.9.0_Server_Java_8.zip' => [
            'sha256' => 'c2a8645a49a1ba9e04591fc0564e604a580d9ddb3a7603e14d62abf5523c2850', 'size' => 553000958,
        ],
        'GT_New_Horizons_2.9.0_Server_Java_17-26.zip' => [
            'sha256' => '0ab3de26e4d0de80a2df286916570d9af31c3394cb1f44aee8b9aa1f2ead9831', 'size' => 568348402,
        ],
    ];
}
