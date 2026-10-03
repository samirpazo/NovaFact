<?php

namespace App\Services\Auth;

use App\Models\Empresa;
use App\Models\McrApiClient;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;

final readonly class ApiScope
{
    public function __construct(public McrApiClient $client, public Empresa $company) {}

    public static function from(Request $request): self
    {
        $scope = $request->attributes->get(self::class);
        abort_unless($scope instanceof self, 401, 'Unauthorized');

        return $scope;
    }

    public function documents(Builder|QueryBuilder $query, string $prefix = ''): Builder|QueryBuilder
    {
        return $query->where($prefix.'McrApiClientID', $this->client->getKey())
            ->where($prefix.'McrCompanyConfigID', $this->company->getKey());
    }
}
