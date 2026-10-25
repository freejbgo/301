<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAdminTenant;
use App\Services\AcmeChallengeCleanup;
use App\Services\CertificateDeployService;
use App\Services\DomainRecordNormalizer;
use App\Support\RediCertLastError;
use Illuminate\Validation\ValidationException;
use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

class RediAdminDomain extends Model
{
    use BelongsToAdminTenant;
    use HasDateTimeFormatter;

    /** @deprecated Use {@see RediCertLastError::DOMAIN_DISABLED_CANCEL} */
    public const DISABLE_DEPLOY_CANCEL_MSG = RediCertLastError::LEGACY_DOMAIN_DISABLED_CANCEL_MSG;

    protected static function booted(): void
    {
        static::saving(function (self $domain) {
            $norm = DomainRecordNormalizer::originalUrlIfValid($domain->original_url);
            if ($norm === '') {
                $attempt = DomainRecordNormalizer::normalizeOriginalUrl($domain->original_url);
                throw ValidationException::withMessages([
                    'original_url' => $attempt === ''
                        ? __('redi-admin-domain.validation.original_empty')
                        : __('redi-admin-domain.validation.original_invalid'),
                ]);
            }
            $domain->original_url = $norm;
            $domain->original_url_hash = DomainRecordNormalizer::originalUrlHash($norm);

            $skip = DomainRecordNormalizer::normalizeSkipUrl($domain->skip_url);
            if ($skip === '') {
                throw ValidationException::withMessages([
                    'skip_url' => __('redi-admin-domain.validation.skip_url_invalid'),
                ]);
            }
            $domain->skip_url = $skip;
        });

        static::saved(function (self $domain) {
            if ($domain->wasChanged('original_url')) {
                $cert = RediDomainCert::query()->where('domain_id', $domain->id)->first();
                if ($cert) {
                    $cert->setRelation('domain', $domain);
                    app(CertificateDeployService::class)->afterCertMaterialOrHostChange($cert);
                }
            }

            if (! $domain->wasChanged('status')) {
                return;
            }

            $newStatus = (int) $domain->status;

            if ($newStatus === 1) {
                if ((int) $domain->getOriginal('status') === 2) {
                    app(CertificateDeployService::class)->handleDomainReenabledFromDisabled($domain);
                }

                return;
            }

            if ($newStatus !== 2) {
                return;
            }

            $purgeHosts = [];
            foreach ([$domain->original_url, $domain->getOriginal('original_url')] as $raw) {
                $raw = (string) $raw;
                if ($raw !== '') {
                    $purgeHosts[] = $raw;
                }
            }
            if ($purgeHosts !== []) {
                AcmeChallengeCleanup::purgeForHosts($purgeHosts);
            }

            $cert = RediDomainCert::query()->where('domain_id', $domain->id)->first();
            if ($cert) {
                $hadDeployments = RediCertDeployment::where('cert_id', $cert->id)->exists();
                RediCertDeployment::where('cert_id', $cert->id)->delete();
                if (in_array($cert->status, [
                    RediDomainCert::STATUS_ACTIVE,
                    RediDomainCert::STATUS_DEPLOYING,
                ], true)) {
                    $cert->status = RediDomainCert::STATUS_ISSUED;
                    RediCertLastError::apply($cert, RediCertLastError::DOMAIN_DISABLED_CANCEL);
                    $cert->save();
                } elseif ($hadDeployments) {
                    RediCertLastError::apply($cert, RediCertLastError::DOMAIN_DISABLED_CANCEL);
                    $cert->save();
                }
                CertificateDeployService::forgetActionableDeploymentMetrics();
            }
        });

        static::deleting(function (self $domain) {
            $cert = RediDomainCert::query()->where('domain_id', $domain->id)->first();
            if ($cert) {
                RediCertDeployment::where('cert_id', $cert->id)->delete();
                CertificateDeployService::forgetActionableDeploymentMetrics();
                $cert->delete();
            }
            $purgeHosts = array_values(array_filter([
                (string) $domain->original_url,
                (string) $domain->getOriginal('original_url'),
            ], static fn (string $raw): bool => $raw !== ''));
            if ($purgeHosts !== []) {
                AcmeChallengeCleanup::purgeForHosts($purgeHosts);
            }
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
