<?php

namespace App\Admin\Actions\Grid;

use App\Admin\Forms\BatchEditDomains as BatchEditDomainsForm;
use Dcat\Admin\Grid\BatchAction;
use Dcat\Admin\Widgets\Modal;

class BatchEditDomains extends BatchAction
{
    public function title()
    {
        return __('redi-admin-domain.actions.batch_edit.title');
    }

    public function render()
    {
        $form = BatchEditDomainsForm::make();

        return Modal::make()
            ->lg()
            ->title($this->title())
            ->body($form)
            ->onLoad($this->getModalScript())
            ->button($this->title());
    }

    protected function getModalScript(): string
    {
        $warning = e(__('redi-admin-domain.batch_mutator.errors.no_selection'));

        return <<<JS
var key = {$this->getSelectedKeysScript()}
if (!key || key.length === 0) {
    Dcat.warning('{$warning}');
    return;
}
$('#batch-edit-domain-ids').val(key.join(','));
JS;
    }
}
