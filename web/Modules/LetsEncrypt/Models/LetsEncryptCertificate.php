<?php

namespace Modules\LetsEncrypt\Models;

use App\Jobs\ApacheBuild;
use App\Models\Domain;
use App\Models\DomainSslCertificate;
use App\Models\HostingSubscription;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Modules\LetsEncrypt\Jobs\LetsEncryptSecureDomain;

class LetsEncryptCertificate extends Model
{
    use HasFactory;

    protected $fillable = [
        'domain_id',
        'email',
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {

            $findDomain = Domain::where('id', $model->domain_id)->first();
            if (!$findDomain) {
                throw new \Exception('Domain not found');
            }

            $findSSL = DomainSslCertificate::where('domain', $findDomain->domain)->first();
            if ($findSSL) {
                throw new \Exception('SSL already exists');
            }

            $findHostingSubscription = HostingSubscription::where('id', $findDomain->hosting_subscription_id)->first();
            if (!$findHostingSubscription) {
                throw new \Exception('Hosting subscription not found');
            }

            $secureDomain = new LetsEncryptSecureDomain($findDomain->id);
            $secureDomain->handle();

            ApacheBuild::dispatchSync();

            $findSSL = DomainSslCertificate::where('domain', $findDomain->domain)->first();
            if ($findSSL) {
                $model->domain_ssl_certificate_id = $findSSL->id;
                $model->certificate = $findSSL->certificate;
                $model->private_key = $findSSL->private_key;
                $model->expires_at = $findSSL->expiration_date;
            }
        });
    }

}
