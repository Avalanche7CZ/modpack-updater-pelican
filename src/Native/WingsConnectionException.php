<?php
declare(strict_types=1);
namespace Arcturus\ModpackManager\Native;

/** Exhausted native directory transport reads, distinct from an upstream download. */
final class WingsConnectionException extends \Illuminate\Http\Client\ConnectionException {}
