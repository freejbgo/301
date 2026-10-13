<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class RediDomainCert extends Model
{
    public const STATUS_NONE = 'none';

    public const STATUS_PENDING_DNS = 'pending_dns';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_DEPLOYING = 'deploying';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    protected $table = 'redi_domain_certs';

    protected $guarded = [
        'certificate',
        'private_key',
    ];

    protected $fillable = [
        'domain_id',
        'admin_id',
        'domain_name',
        'status',
        'method',
        'expires_at',
        'last_error',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function domain()
    {
        return $this->belongsTo(RediAdminDomain::class, 'domain_id');
    }

    public function setCertificatePem(string $pem): void
    {
        $this->certificate = Crypt::encryptString($pem);
    }

    public function getCertificatePem(): ?string
    {
        if (! $this->certificate) {
            return null;
        }

        try {
            return Crypt::decryptString($this->certificate);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function storedCredentialsUnreadable(): bool
    {
        if ($this->certificate && $this->getCertificatePem() === null) {
            return true;
        }
        if ($this->private_key && $this->getPrivateKeyPem() === null) {
            return true;
        }

        return false;
    }

    public function setPrivateKeyPem(string $pem): void
    {
        $this->private_key = Crypt::encryptString($pem);
    }

    public function getPrivateKeyPem(): ?string
    {
        if (! $this->private_key) {
            return null;
        }

        try {
            return Crypt::decryptString($this->private_key);
        } catch (\Throwable $e) {
            return null;
        }
    }

    public function statusLabel(): string
    {
        return [
            self::STATUS_NONE => '未申请',
            self::STATUS_PENDING_DNS => '待解析',
            self::STATUS_VALIDATING => '验证中',
            self::STATUS_ISSUED => '已签发',
            self::STATUS_DEPLOYING => '部署中',
            self::STATUS_ACTIVE => '已生效',
            self::STATUS_FAILED => '失败',
        ][$this->status] ?? $this->status;
    }
}
