<?php

namespace App\Admin\Controllers;

use App\Admin\Concerns\EnsuresAdministrator;
use App\Http\Controllers\Controller;
use App\Services\CertificateDeployService;
use App\Support\SafeRelativeRedirect;
use Illuminate\Http\Request;

class OpsController extends Controller
{
    use EnsuresAdministrator;

    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            $this->ensureAdministrator();

            return $next($request);
        });
    }

    public function requeueFailedDeployments(Request $request, CertificateDeployService $deployService)
    {
        $count = $deployService->requeueFailedDeployments(null, null);

        if ($count === 0) {
            admin_toastr(__('redi-home.ops.requeue_empty'), 'info');
        } else {
            admin_toastr(__('redi-home.ops.requeue_success', ['count' => $count]), 'success');
        }

        $redirect = SafeRelativeRedirect::normalizeForRedirect(
            is_string($request->input('_redirect')) ? $request->input('_redirect') : null
        );
        if ($redirect !== null) {
            return redirect($redirect);
        }

        return redirect(admin_url('/'));
    }
}
