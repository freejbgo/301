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
  if (window.__rediBatchFilterDomainSync) {
    return;
  }
  window.__rediBatchFilterDomainSync = true;
  var filterKeys = {$keys};
  var modalId = '{$id}';
  function addId(ids, seen, id) {
    id = String(id == null ? '' : id).trim();
    if (!/^[1-9][0-9]*$/.test(id) || seen[id]) {
      return;
    }
    seen[id] = true;
    ids.push(id);
  }
  function collectSelectedIds() {
    var ids = [];
    var seen = {};
    var grid = window.Dcat && Dcat.grid;
    if (grid && grid.selectors) {
      Object.keys(grid.selectors).forEach(function (name) {
        var selector = grid.selectors[name];
        if (!selector || typeof selector.getSelectedKeys !== 'function') {
          return;
        }
        selector.getSelectedKeys().forEach(function (id) {
          addId(ids, seen, id);
        });
      });
    }
    document.querySelectorAll('.checkbox-grid-column input[type="checkbox"]:checked, input[class*="grid-row-checkbox"]:checked').forEach(function (box) {
      addId(ids, seen, box.getAttribute('data-id') || box.value);
    });
    return ids;
  }
  function readFilter(key) {
    var field = document.querySelector('.grid-filter-form [name="' + key + '"]');
    var fromForm = field ? String(field.value || '').trim() : '';
    if (fromForm !== '') {
      return fromForm;
    }
    return new URLSearchParams(window.location.search).get(key) || '';
  }
  function syncBatchFilterFields() {
    var modal = document.getElementById(modalId);
    if (!modal) {
      return;
    }
    filterKeys.forEach(function (key) {
      var input = modal.querySelector('input[name="' + key + '"]');
      if (input) {
        input.value = readFilter(key);
      }
    });
    var selected = collectSelectedIds().join(',');
    var inputs = modal.querySelectorAll('input[name="selected_ids"]');
    if (!inputs.length) {
      var form = modal.querySelector('form');
      if (!form) {
        return;
      }
      var created = document.createElement('input');
      created.type = 'hidden';
      created.name = 'selected_ids';
      created.value = selected;
      form.appendChild(created);
      return;
    }
    inputs.forEach(function (input) {
      input.value = selected;
    });
  }
  if (window.jQuery && jQuery.fn && typeof jQuery.fn.ajaxSubmit === 'function' && !jQuery.fn.ajaxSubmit.__rediBatchFilter) {
    var originalAjaxSubmit = jQuery.fn.ajaxSubmit;
    var wrappedAjaxSubmit = function (options) {
      var form = this && this[0];
      if (form && form.closest && form.closest('#' + modalId)) {
        syncBatchFilterFields();
      }
      return originalAjaxSubmit.apply(this, arguments);
    };
    wrappedAjaxSubmit.__rediBatchFilter = true;
    jQuery.fn.ajaxSubmit = wrappedAjaxSubmit;
  }
  document.addEventListener('click', function (event) {
    var target = event.target;
    if (!target || !target.closest) {
      return;
    }
    if (target.closest('[data-target="#' + modalId + '"]')) {
      syncBatchFilterFields();
      return;
    }
    var modal = document.getElementById(modalId);
    if (!modal || !modal.contains(target)) {
      return;
    }
    if (target.closest('button[type="submit"], .submit')) {
      syncBatchFilterFields();
    }
  }, true);
  $(document).on('show.bs.modal', '#' + modalId, syncBatchFilterFields);
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
