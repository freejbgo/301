<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAdminTenant;
use App\Services\AcmeChallengeCleanup;
use App\Services\DomainRecordNormalizer;
use Illuminate\Validation\ValidationException;
use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

class RediAdminDomain extends Model
{
    use BelongsToAdminTenant;
    use HasDateTimeFormatter;

    protected static function booted(): void
    {
        static::saving(function (self $domain) {
            if (! DomainRecordNormalizer::isValidOriginalHost($domain->original_url)) {
                $norm = DomainRecordNormalizer::normalizeOriginalUrl($domain->original_url);
                throw ValidationException::withMessages([
                    'original_url' => $norm === ''
                        ? '原始域名不能为空或含路径'
                        : '原始域名格式无效',
                ]);
            }
            $norm = DomainRecordNormalizer::normalizeOriginalUrl($domain->original_url);
            $domain->original_url = $norm;
            $domain->original_url_hash = DomainRecordNormalizer::originalUrlHash($norm);

            $skip = DomainRecordNormalizer::normalizeSkipUrl($domain->skip_url);
            if ($skip === '') {
                throw ValidationException::withMessages([
                    'skip_url' => '跳转链接无效（须为 http/https 完整 URL）',
                ]);
            }
            $domain->skip_url = $skip;
        });

        static::saved(function (self $domain) {
            if (! $domain->wasChanged('status') || (int) $domain->status !== 2) {
                return;
            }
            $hosts = array_filter([
                $domain->original_url,
                $domain->getOriginal('original_url'),
            ]);
            AcmeChallengeCleanup::purgeForHosts($hosts);
        });

        static::deleting(function (self $domain) {
            $cert = RediDomainCert::query()->where('domain_id', $domain->id)->first();
            if ($cert) {
                RediCertDeployment::where('cert_id', $cert->id)->delete();
                $cert->delete();
            }
            AcmeChallengeCleanup::purgeForHosts([$domain->original_url]);
        });
    }

    protected $table = 'redi_admin_domain';

    protected $fillable = [
        'group_id',
        'original_url',
        'original_url_hash',
        'skip_url',
        'status',
        'force_https',
    ];

    protected $guarded = [
        'admin_id',
        'admin_name',
        'skip_num',
    ];

    protected $casts = [
        'force_https' => 'boolean',
    ];

    public function group()
    {
        return $this->belongsTo(RediAdminGroup::class, 'group_id', 'id');
    }

    public function certificate()
    {
        return $this->hasOne(RediDomainCert::class, 'domain_id');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<self>  $query
     */
    public static function applyCertificateListFilter($query, ?string $filter): void
    {
        $filter = trim((string) $filter);
        if ($filter === '') {
            return;
        }

        switch ($filter) {
            case 'needs_attention':
                $query->whereHas('certificate', function ($q) {
                    $q->whereIn('status', [
                        RediDomainCert::STATUS_PENDING_DNS,
                        RediDomainCert::STATUS_FAILED,
                        RediDomainCert::STATUS_DEPLOYING,
                        RediDomainCert::STATUS_VALIDATING,
                    ]);
                });
                break;
            case 'active':
                $query->whereHas('certificate', function ($q) {
                    $q->where('status', RediDomainCert::STATUS_ACTIVE);
                });
                break;
            case 'expiring':
                $query->whereHas('certificate', function ($q) {
                    $q->whereIn('status', [
                        RediDomainCert::STATUS_ACTIVE,
                        RediDomainCert::STATUS_ISSUED,
                        RediDomainCert::STATUS_DEPLOYING,
                    ])
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '>=', now())
                        ->where('expires_at', '<=', now()->addDays(14));
                });
                break;
            case 'none':
                $query->whereDoesntHave('certificate');
                break;
            default:
                break;
        }
    }
}
