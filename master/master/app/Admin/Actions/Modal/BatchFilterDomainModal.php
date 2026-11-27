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
  var modal = document.getElementById('{$id}');
  function readFilter(key) {
    var field = document.querySelector('.grid-filter-form [name="' + key + '"]');
    var fromForm = field ? String(field.value || '').trim() : '';
    if (fromForm !== '') {
      return fromForm;
    }
    return new URLSearchParams(window.location.search).get(key) || '';
  }
  function syncBatchFilterFields() {
    if (!modal) {
      return;
    }
    filterKeys.forEach(function (key) {
      var input = modal.querySelector('input[name="' + key + '"]');
      if (input) {
        input.value = readFilter(key);
      }
    });
    var seen = {};
    var ids = [];
    document.querySelectorAll('input[class*="grid-row-checkbox"]:checked').forEach(function (box) {
      var id = box.getAttribute('data-id') || '';
      if (!/^[1-9][0-9]*$/.test(id) || seen[id]) {
        return;
      }
      seen[id] = true;
      ids.push(id);
    });
    var idInput = modal.querySelector('input[name="selected_ids"]');
    if (idInput) {
      idInput.value = ids.join(',');
    }
  }
  if (modal) {
    $('#{$id}').on('show.bs.modal', syncBatchFilterFields);
    modal.addEventListener('click', function (event) {
      var target = event.target;
      if (target && target.closest && target.closest('button[type="submit"]')) {
        syncBatchFilterFields();
      }
    }, true);
  }
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
