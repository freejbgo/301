<?php

namespace App\Models;

use App\Services\CertificateDeployService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class RediDomainCert extends Model
{
    protected static function booted(): void
    {
        static::saved(function (self $cert) {
            if (! $cert->wasChanged(['domain_name', 'certificate', 'private_key', 'domain_id'])) {
                return;
            }

            $cert->loadMissing('domain');
            app(CertificateDeployService::class)->afterCertMaterialOrHostChange($cert);
        });
    }

    public const STATUS_NONE = 'none';

    public const STATUS_PENDING_DNS = 'pending_dns';

    public const STATUS_VALIDATING = 'validating';

    public const STATUS_ISSUED = 'issued';

    public const STATUS_DEPLOYING = 'deploying';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_FAILED = 'failed';

    /**
     * 续期 cron 可入队的状态：进行中 validating 仅在超过卡住阈值后重试。
     */
    public function scopeRenewalDue($query, \DateTimeInterface $expiresBefore, int $validatingStuckHours = 3)
    {
        $stuckBefore = now()->subHours(max(1, $validatingStuckHours));

        return $query
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $expiresBefore)
            ->where(function ($statusQuery) use ($stuckBefore) {
                $statusQuery->whereIn('status', [
                    self::STATUS_ACTIVE,
                    self::STATUS_ISSUED,
                    self::STATUS_DEPLOYING,
                    self::STATUS_FAILED,
                ])->orWhere(function ($inner) use ($stuckBefore) {
                    $inner->where('status', self::STATUS_VALIDATING)
                        ->where('updated_at', '<=', $stuckBefore);
                });
            });
    }

    protected $table = 'redi_domain_certs';

    protected $guarded = [
        'certificate',
        'private_key',
        'admin_id',
    ];

    protected $fillable = [
        'domain_id',
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

    public function deployments()
    {
        return $this->hasMany(RediCertDeployment::class, 'cert_id');
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

    /** 解密失败时写入 last_error 并记日志（供节点拉取证书路径调用）。 */
    public function flagCredentialUnreadableIfNeeded(): void
    {
        if (! $this->storedCredentialsUnreadable()) {
            return;
        }
        $msg = '证书或私钥无法解密（APP_KEY 变更或数据损坏），请重新申请证书';
        if ($this->last_error === $msg) {
            return;
        }
        $this->last_error = $msg;
        $this->saveQuietly();
        Log::warning('redi_domain_cert credential decrypt failed', ['cert_id' => $this->id]);
        $this->loadMissing('domain');
        app(CertificateDeployService::class)->afterCertMaterialOrHostChange($this);
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
