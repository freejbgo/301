<?php

namespace App\Models;

use App\Models\Concerns\BelongsToAdminTenant;
use App\Services\DomainRecordNormalizer;
use Dcat\Admin\Traits\HasDateTimeFormatter;
use Illuminate\Database\Eloquent\Model;

class RediAdminDomain extends Model
{
    use BelongsToAdminTenant;
    use HasDateTimeFormatter;

    protected static function booted(): void
    {
        static::saving(function (self $domain) {
            $norm = DomainRecordNormalizer::normalizeOriginalUrl($domain->original_url);
            if ($norm !== '') {
                $domain->original_url = $norm;
                $domain->original_url_hash = DomainRecordNormalizer::originalUrlHash($norm);
            }
        });

        static::deleting(function (self $domain) {
            $cert = RediDomainCert::query()->where('domain_id', $domain->id)->first();
            if ($cert) {
                RediCertDeployment::where('cert_id', $cert->id)->delete();
                $cert->delete();
            }
            RediAcmeChallenge::query()
                ->where('domain_name', $domain->original_url)
                ->delete();
            RediAcmeChallengeNodeSync::query()
                ->where('domain_name', $domain->original_url)
                ->delete();
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
}
