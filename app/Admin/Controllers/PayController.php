<?php

namespace App\Admin\Controllers;

use App\Admin\Actions\Post\BatchRestore;
use App\Admin\Actions\Post\Restore;
use App\Admin\Repositories\Pay;
use Dcat\Admin\Form;
use Dcat\Admin\Grid;
use Dcat\Admin\Show;
use Dcat\Admin\Http\Controllers\AdminController;
use App\Models\Pay as PayModel;
use App\Models\BinancePaySetting;

class PayController extends AdminController
{


    /**
     * Make a grid builder.
     *
     * @return Grid
     */
    protected function grid()
    {
        return Grid::make(new Pay(), function (Grid $grid) {
            $grid->column('id')->sortable();
            $grid->column('pay_name');
            $grid->column('pay_check');
            $grid->column('pay_method')->select(PayModel::getMethodMap());
            $grid->column('merchant_id')->limit(20);
            $grid->column('pay_client')->select(PayModel::getClientMap());
            $grid->column('pay_handleroute');
            $grid->column('is_open')->if(function ($column) {
                return (string) $this->pay_check !== 'binancepay';
            })->switch()->else()->display(function () {
                return $this->is_open == PayModel::STATUS_OPEN
                    ? admin_trans('dujiaoka.status_open')
                    : admin_trans('dujiaoka.status_close');
            })->end();
            $grid->column('created_at');
            $grid->column('updated_at')->sortable();
            $grid->disableDeleteButton();
            $grid->filter(function (Grid\Filter $filter) {
                $filter->equal('id');
                $filter->equal('pay_check');
                $filter->like('pay_name');
                $filter->scope(admin_trans('dujiaoka.trashed'))->onlyTrashed();
            });
            $grid->actions(function (Grid\Displayers\Actions $actions) {
                if ($actions->row->pay_check === 'binancepay') {
                    $setting = BinancePaySetting::current();
                    $status = $setting->enabled && $setting->hasSuccessfulConnectionTest()
                        ? admin_trans('pay.binance.status.ready')
                        : admin_trans('pay.binance.status.missing');
                    $actions->append(
                        '<a href="' . admin_url('binance-pay') . '" class="btn btn-sm btn-primary">'
                        . '<i class="fa fa-cog"></i> ' . admin_trans('pay.binance.title') . ' (' . $status . ')</a>'
                    );
                }
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $actions->append(new Restore(PayModel::class));
                }
            });
            $grid->batchActions(function (Grid\Tools\BatchActions $batch) {
                if (request('_scope_') == admin_trans('dujiaoka.trashed')) {
                    $batch->add(new BatchRestore(PayModel::class));
                }
            });
        });
    }

    /**
     * Make a show builder.
     *
     * @param mixed $id
     *
     * @return Show
     */
    protected function detail($id)
    {
        return Show::make($id, new Pay(), function (Show $show) {
            $show->field('id');
            $show->field('pay_name');
            $show->field('merchant_id');
            $show->field('merchant_key');
            $show->field('merchant_pem');
            $show->field('pay_check');
            $show->field('pay_client')->as(function ($payClient) {
                if ($payClient == PayModel::PAY_CLIENT_PC) {
                    return admin_trans('pay.fields.pay_client_pc');
                } else {
                    return admin_trans('pay.fields.pay_client_mobile');
                }
            });
            $show->field('pay_handleroute');
            $show->field('pay_method')->as(function ($payMethod) {
                if ($payMethod == PayModel::METHOD_JUMP) {
                    return admin_trans('pay.fields.method_jump');
                } else {
                    return admin_trans('pay.fields.method_scan');
                }
            });
            $show->field('is_open')->as(function ($isOpen) {
                if ($isOpen == PayModel::STATUS_OPEN) {
                    return admin_trans('dujiaoka.status_open');
                } else {
                    return admin_trans('dujiaoka.status_close');
                }
            });
            $show->field('created_at');
            $show->field('updated_at');
        });
    }

    /**
     * Make a form builder.
     *
     * @return Form
     */
    protected function form()
    {
        return Form::make(new Pay(), function (Form $form) {
            $isBinancePay = (string) $form->model()->pay_check === 'binancepay';
            if ($isBinancePay) {
                $form->html(
                    '<div class="alert alert-info">'
                    . admin_trans('pay.binance.description')
                    . ' <a class="btn btn-sm btn-primary ml-1" href="' . admin_url('binance-pay') . '">'
                    . admin_trans('pay.binance.title') . '</a></div>'
                );
                $form->display('id');
                $form->display('pay_name');
                $form->display('merchant_id');
                $form->display('pay_check');
                $form->display('pay_client');
                $form->display('pay_method');
                $form->display('pay_handleroute');
                $form->display('is_open', admin_trans('pay.fields.is_open'))->customFormat(function ($value) {
                    return (int) $value === PayModel::STATUS_OPEN
                        ? admin_trans('dujiaoka.status_open')
                        : admin_trans('dujiaoka.status_close');
                });
                $form->display('created_at');
                $form->display('updated_at');
                // Ignore forged POST fields too; this record is maintained by BinancePaySettingForm.
                $form->ignore([
                    'pay_name', 'merchant_id', 'merchant_key', 'merchant_pem', 'pay_check',
                    'pay_client', 'pay_method', 'pay_handleroute', 'is_open',
                ]);
                $form->disableDeleteButton();
                return;
            }

            $form->display('id');
            $form->text('pay_name')->required();
            $form->text('merchant_id')->required();
            $form->textarea('merchant_key');
            $form->textarea('merchant_pem')->required();
            $form->text('pay_check')->required();
            $form->radio('pay_client')
                ->options(PayModel::getClientMap())
                ->default(PayModel::PAY_CLIENT_PC)
                ->required();
            $form->radio('pay_method')
                ->options(PayModel::getMethodMap())
                ->default(PayModel::METHOD_JUMP)
                ->required();
            $form->text('pay_handleroute')->required();
            if (!$isBinancePay) {
                $form->switch('is_open')->default(PayModel::STATUS_OPEN);
            }
            $form->display('created_at');
            $form->display('updated_at');
            $form->disableDeleteButton();
        });
    }
}
