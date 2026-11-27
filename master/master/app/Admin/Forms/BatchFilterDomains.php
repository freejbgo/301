<?php

namespace App\Admin\Forms;

use App\Services\DomainBatchFilterRunner;
use App\Services\DomainListFilter;
use Dcat\Admin\Admin;
use Dcat\Admin\Contracts\LazyRenderable;
use Dcat\Admin\Traits\LazyWidget;
use Dcat\Admin\Widgets\Form;
use Illuminate\Http\Request;

class BatchFilterDomains extends Form implements LazyRenderable
{
    use LazyWidget;

    public function handle(array $input)
    {
        $action = (string) ($input['action'] ?? '');
        $filterRequest = $this->filterRequestFromInput($input);

        $runner = new DomainBatchFilterRunner();
        $out = $runner->run($action, $filterRequest, (int) Admin::user()->id);

        if (! $out['ok']) {
            return $this->response()->error($out['message']);
        }

        return $this->response()->success($out['message'])->refresh();
    }

    public function form()
    {
        $this->confirm(
            __('redi-admin-domain.batch_filter.confirm_title'),
            __('redi-admin-domain.batch_filter.confirm_content')
        );

        $options = [
            'apply_cert' => __('redi-admin-domain.batch_filter.actions.apply_cert'),
            'redeploy' => __('redi-admin-domain.batch_filter.actions.redeploy'),
            'enable' => __('redi-admin-domain.batch_filter.actions.enable'),
            'disable' => __('redi-admin-domain.batch_filter.actions.disable'),
            'delete' => __('redi-admin-domain.batch_filter.actions.delete'),
        ];

        $this->select('action', __('redi-admin-domain.batch_filter.fields.action'))
            ->options($options)
            ->required()
            ->help(__('redi-admin-domain.batch_filter.help.action', [
                'max' => \App\Services\DomainBatchMutator::MAX_IDS_PER_BATCH,
            ]));

        foreach (DomainListFilter::FILTER_KEYS as $key) {
            $this->hidden($key)->addElementClass('batch-filter-domain-field', false);
        }
        $this->hidden('selected_ids')->addElementClass('batch-filter-domain-ids', false);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    protected function filterRequestFromInput(array $input): Request
    {
        $bag = [];
        foreach (DomainListFilter::FILTER_KEYS as $key) {
            if (isset($input[$key]) && $input[$key] !== '') {
                $bag[$key] = $input[$key];
            }
        }

        $request = DomainListFilter::mergeFilterIntoRequest(request(), $bag);
        $selected = $input['selected_ids'] ?? '';
        if (is_array($selected)) {
            $selected = implode(',', $selected);
        }
        $selected = trim((string) $selected);
        if ($selected !== '') {
            $request->merge(['selected_ids' => $selected]);
        }

        return $request;
    }
}
