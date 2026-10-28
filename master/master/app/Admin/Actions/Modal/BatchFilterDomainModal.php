<?php

namespace App\Admin\Actions\Modal;

use App\Admin\Forms\BatchFilterDomains;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\Tools\AbstractTool;

class BatchFilterDomainModal extends AbstractTool
{
    public function render()
    {
        $id = 'batch-filter-domain-modal';
        $btnLabel = e(__('redi-admin-domain.labels.batch_filter'));

        $this->modal($id);

        return <<<HTML
<span class="grid-expand" data-toggle="modal" data-target="#{$id}">
   <a href="javascript:void(0)"><button class="btn btn-default btn-sm" style="margin-left:6px">{$btnLabel}</button></a>
</span>
HTML;
    }

    protected function modal(string $id): void
    {
        $form = new BatchFilterDomains();
        $title = e(__('redi-admin-domain.labels.batch_filter'));

        $keys = json_encode(\App\Services\DomainListFilter::FILTER_KEYS);

        Admin::script(<<<JS
Dcat.ready(function () {
  var filterKeys = {$keys};
  function syncBatchFilterFields() {
    var params = new URLSearchParams(window.location.search);
    filterKeys.forEach(function (key) {
      var val = params.get(key) || '';
      $('.batch-filter-domain-field[name="' + key + '"]').val(val);
    });
  }
  $('#{$id}').on('show.bs.modal', syncBatchFilterFields);
});
JS
        );

        Admin::html(
            <<<HTML
<div class="modal fade" id="{$id}">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header">
        <h4 class="modal-title">{$title}</h4>
        <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
      </div>
      <div class="modal-body">
        {$form->render()}
      </div>
    </div>
  </div>
</div>
HTML
        );
    }
}
