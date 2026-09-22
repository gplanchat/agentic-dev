<?php

declare(strict_types=1);

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

final class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    public function boot(): void
    {
        // The logger writes to a file (see config/services.php): its directory must exist.
        is_dir($this->getLogDir()) || mkdir($this->getLogDir(), 0o777, true);

        parent::boot();
    }
}
