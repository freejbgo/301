<?php

namespace App\Admin\Repositories;

use App\Models\RediAdminDomain as Model;
use Dcat\Admin\Repositories\EloquentRepository;

class RediAdminDomain extends EloquentRepository
{
    /**
     * Model.
     *
     * @var string
     */
    protected $eloquentClass = Model::class;
}
