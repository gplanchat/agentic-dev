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
        // Le logger écrit dans un fichier (voir config/services.php) : son dossier doit exister.
        is_dir($this->getLogDir()) || mkdir($this->getLogDir(), 0o777, true);

        parent::boot();
    }
}
