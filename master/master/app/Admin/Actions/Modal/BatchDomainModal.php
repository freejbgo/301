<?php

namespace App\Admin\Actions\Modal;

use App\Admin\Forms\BatchCreateDomains;
use Dcat\Admin\Admin;
use Dcat\Admin\Grid\Tools\AbstractTool;

class BatchDomainModal extends AbstractTool
{
    public function render()
    {
        $id = 'batch-domain-modal';
        $btnLabel = e(__('redi-admin-domain.labels.batch_add_domains'));

        $this->modal($id);

        return <<<HTML
<span class="grid-expand" data-toggle="modal" data-target="#{$id}">
   <a href="javascript:void(0)"><button class="btn btn-primary">{$btnLabel}</button></a>
</span>
HTML;
    }

    protected function modal(string $id): void
    {
        $form = new BatchCreateDomains();
        $title = e(__('redi-admin-domain.labels.batch_add_domains'));

        Admin::script('Dcat.onPjaxComplete(function () {
            $(".modal-backdrop").remove();
            $("body").removeClass("modal-open");
        }, true)');

        Admin::html(
            <<<HTML
<div class="modal fade" id="{$id}">
  <div class="modal-dialog modal-lg" role="document">
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
