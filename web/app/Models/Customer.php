<?php

namespace App\Models;

use App\ApiSDK\PhyreApiSDK;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    protected $fillable = [
        'phyre_server_id',
        'name',
        'username',
        'password',
        'email',
        'phone',
        'address',
        'city',
        'state',
        'zip',
        'country',
        'company',
    ];

    public static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if ($model->phyre_server_id > 0) {
                $phyreServer = PhyreServer::where('id', $model->phyre_server_id)->first();
                if ($phyreServer) {
                    $phyreApiSDK = new PhyreApiSDK($phyreServer->ip, 8443, $phyreServer->username, $phyreServer->password);
                    $createCustomer = $phyreApiSDK->createCustomer([
                        'name' => $model->name,
                        'username' => $model->username,
                        'password' => $model->password,
                        'email' => $model->email,
                        'phone' => $model->phone,
                        'address' => $model->address,
                        'city' => $model->city,
                        'state' => $model->state,
                        'zip' => $model->zip,
                        'country' => $model->country,
                        'company' => $model->company,
                    ]);
                    if (isset($createCustomer['data']['customer']['id'])) {
                        $model->external_id = $createCustomer['data']['customer']['id'];
                    } else {
                        return false;
                    }

                } else {
                    return false;
                }
            }
        });

        static::deleting(function ($model) {

        });

    }

    public function hostingSubscriptions()
    {
        return $this->hasMany(HostingSubscription::class);
    }
}
