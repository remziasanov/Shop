<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Pages;

use App\Enums\OrderType;
use App\Exceptions\ConcurrentEditException;
use App\Exceptions\SalesException;
use App\Filament\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\User;
use App\Services\Orders\EditData;
use App\Services\Orders\EditItem;
use App\Services\Orders\EditPlan;
use App\Services\Orders\EditPreviewPresenter;
use App\Services\Orders\OrderEditor;
use App\Services\Orders\OrderItemSearch;
use App\Services\Orders\OrderService;
use App\Settings\NotificationSettings;
use App\Settings\SalesSettings;
use App\Support\Money;
use App\Support\QtyLimit;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Repeater\TableColumn;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Livewire\Attributes\Locked;

/**
 * The order contents editor (OE-02, 24.3) and, for an inquiry, «Создать заказ из заявки» (OE-12): one form with the
 * positions, the delivery and the discount, the contacts and the «Уведомить покупателя» checkbox. «Предпросмотр и
 * сохранить» opens the was/will table; the confirmation saves through {@see OrderEditor} (the server re-checks the
 * version, the status, the permissions and the stock). The version the form was opened with is kept in a locked
 * property (OE-08).
 *
 * @property-read Schema $form
 */
class EditOrderContents extends Page
{
    use InteractsWithRecord;

    protected static string $resource = OrderResource::class;

    protected string $view = 'filament.orders.edit-contents';

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    /** The version of the order when the form was opened (hidden state of OE-08). */
    #[Locked]
    public int $version = 1;

    /**
     * The formatted preview shown in the confirmation modal.
     *
     * @var array<string, mixed>
     */
    public array $preview = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
        $user = $this->actor();
        abort_unless($user !== null, 403);

        if ($this->isInquiry()) {
            abort_unless(Gate::allows('createFromInquiry', $this->order()), 403);
        } else {
            abort_unless(Gate::allows('editItems', $this->order()), 403);
        }

        if (! $this->isInquiry() && ! OrderEditor::isEditable($this->order())) {
            Notification::make()->warning()->title((string) OrderEditor::blockReason($this->order()))->send();
            $this->redirect(OrderResource::getUrl('view', ['record' => $this->order()]));

            return;
        }

        $this->version = (int) $this->order()->version;
        $this->form->fill($this->initialState());
    }

    public function getTitle(): string
    {
        return $this->isInquiry()
            ? __('order_edit.page.inquiry_title', ['number' => $this->order()->number])
            : __('order_edit.page.title', ['number' => $this->order()->number]);
    }

    public function getBreadcrumb(): string
    {
        return $this->isInquiry() ? __('order_edit.page.inquiry_breadcrumb') : __('order_edit.page.breadcrumb');
    }

    public function order(): Order
    {
        /** @var Order $order */
        $order = $this->record;

        return $order;
    }

    public function isInquiry(): bool
    {
        return $this->order()->type === OrderType::Inquiry;
    }

    public function canEditPrices(): bool
    {
        $user = $this->actor();

        return $user !== null && app(OrderEditor::class)->mayEditPrices($user);
    }

    /**
     * @return array<string, mixed>
     */
    private function initialState(): array
    {
        $order = $this->order();
        $editor = app(OrderEditor::class);
        $rows = [];

        foreach ($order->items()->get() as $item) {
            $price = $item->price;

            if ($this->isInquiry() && $item->product_id !== null) {
                // OE-12: the prices of a new order come from the price list for the group of the inquiry.
                $listed = $editor->listPrice($order, $item->product_id, $item->variant_id, $item->qty);
                $price = $listed === null ? null : Money::toRubles($listed);
            }

            $rows[(string) Str::uuid()] = [
                'item_id' => $this->isInquiry() ? null : $item->id,
                'product_id' => $item->product_id,
                'variant_id' => $item->variant_id,
                'name' => $item->displayName(),
                'sku' => $item->sku,
                'unit' => $item->unit,
                'qty' => $item->qty,
                'price' => $price ?? '0.00',
                'stored_price' => $this->isInquiry() ? null : $price,
                'recalc' => false,
                'recalc_price' => null,
            ];
        }

        $notifications = app(NotificationSettings::class);

        return [
            'add_product' => null,
            'items' => $rows,
            'customer_name' => $order->customer_name,
            'customer_phone' => $order->customer_phone,
            'customer_email' => $order->customer_email,
            'delivery_method' => $order->delivery_method,
            'delivery_address' => $order->delivery_address,
            'delivery_price' => Money::toRubles(Money::fromRubles($order->delivery_price)),
            'manual_discount' => Money::toRubles(Money::fromRubles((string) $order->manual_discount)),
            'customer_comment' => $order->customer_comment,
            'manager_comment' => $order->manager_comment,
            'notify' => $this->isInquiry() ? $notifications->notify_customer_new : $notifications->notify_customer_order_changed,
        ];
    }

    public function form(Schema $schema): Schema
    {
        $canPrices = $this->canEditPrices();
        $showDelivery = $this->order()->type !== OrderType::Reservation;

        return $schema
            ->statePath('data')
            ->components([
                Section::make(__('order_edit.page.items'))
                    ->schema([
                        Select::make('add_product')
                            ->label(__('order_edit.page.add_product'))
                            ->placeholder(__('order_edit.page.add_product_placeholder'))
                            ->searchable()
                            ->live()
                            ->dehydrated(false)
                            ->getSearchResultsUsing(fn (string $search): array => app(OrderItemSearch::class)->options($search))
                            ->getOptionLabelUsing(function (?string $value): ?string {
                                $parsed = OrderItemSearch::parse((string) $value);

                                return $parsed === null ? null : app(OrderEditor::class)->describeUnit($parsed[0], $parsed[1])['name'] ?? null;
                            })
                            ->afterStateUpdated(fn (?string $state) => $this->addProduct($state)),
                        Repeater::make('items')
                            ->hiddenLabel()
                            ->table([
                                TableColumn::make(__('order_edit.page.col_item')),
                                TableColumn::make(__('order_edit.page.col_qty'))->width('8rem'),
                                TableColumn::make(__('order_edit.page.col_price'))->width('11rem'),
                                TableColumn::make(__('order_edit.page.col_sum'))->width('9rem')->alignEnd(),
                            ])
                            ->addable(false)
                            ->reorderable(false)
                            ->minItems(1)
                            ->schema([
                                Hidden::make('item_id'),
                                Hidden::make('product_id'),
                                Hidden::make('variant_id'),
                                Hidden::make('name'),
                                Hidden::make('sku'),
                                Hidden::make('unit'),
                                Hidden::make('stored_price'),
                                Hidden::make('recalc'),
                                Hidden::make('recalc_price'),
                                Text::make(fn (Get $get): string => trim((string) $get('name')).' · '.$get('sku')),
                                TextInput::make('qty')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->integer()
                                    ->minValue(1)
                                    ->maxValue(QtyLimit::MAX)
                                    ->required()
                                    ->live(onBlur: true),
                                TextInput::make('price')
                                    ->hiddenLabel()
                                    ->numeric()
                                    ->minValue(0)
                                    ->step('0.01')
                                    ->required()
                                    ->readOnly(! $canPrices)
                                    ->live(onBlur: true)
                                    ->hint(fn (Get $get): ?string => $get('recalc') && $get('stored_price') !== null
                                        ? __('order_edit.page.was_price', ['price' => Money::format(Money::fromRubles((string) $get('stored_price')))])
                                        : null)
                                    ->hintColor('warning'),
                                Text::make(fn (Get $get): string => Money::format($this->rowSum($get('qty'), $get('price'))))->weight('bold'),
                            ]),
                        Actions::make([
                            Action::make('recalculate')
                                ->label(__('order_edit.page.recalculate'))
                                ->icon(Heroicon::OutlinedArrowPath)
                                ->color('gray')
                                ->visible($canPrices && ! $this->isInquiry())
                                ->action(fn () => $this->recalculatePrices()),
                        ]),
                        Text::make(fn (Get $get): string => __('order_edit.page.items_total', ['sum' => Money::format($this->itemsSum($get('items')))]))->weight('bold'),
                    ]),

                Section::make(__('order_edit.page.delivery'))
                    ->visible($showDelivery)
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        Select::make('delivery_method')
                            ->label(__('admin_orders.view.delivery_method'))
                            ->options(fn (): array => $this->deliveryOptions())
                            ->native(false)
                            ->placeholder('—'),
                        TextInput::make('delivery_price')
                            ->label(__('order_edit.fields.delivery_price'))
                            ->numeric()
                            ->minValue(0)
                            ->step('0.01')
                            ->required()
                            ->suffix('₽'),
                        Textarea::make('delivery_address')
                            ->label(__('admin_orders.view.delivery_address'))
                            ->rows(2)
                            ->maxLength(2000)
                            ->columnSpanFull(),
                    ]),

                Section::make(__('order_edit.page.discount'))
                    ->columns(['default' => 1, 'md' => 2])
                    ->schema([
                        TextInput::make('manual_discount')
                            ->label(__('order_edit.fields.manual_discount'))
                            ->helperText($canPrices ? __('order_edit.page.discount_help') : __('order_edit.page.discount_forbidden'))
                            ->numeric()
                            ->minValue(0)
                            ->step('0.01')
                            ->required()
                            ->suffix('₽')
                            ->readOnly(! $canPrices),
                    ]),

                Section::make(__('order_edit.page.contacts'))
                    ->columns(['default' => 1, 'md' => 3])
                    ->schema([
                        TextInput::make('customer_name')->label(__('admin_orders.view.name'))->required()->maxLength(255),
                        TextInput::make('customer_phone')->label(__('admin_orders.view.phone'))->required()->maxLength(32),
                        TextInput::make('customer_email')->label(__('admin_orders.view.email'))->email()->maxLength(255),
                        Textarea::make('customer_comment')->label(__('admin_orders.view.customer_comment'))->rows(2)->maxLength(5000),
                        Textarea::make('manager_comment')->label(__('admin_orders.view.manager_comment'))->rows(2)->maxLength(5000),
                        Toggle::make('notify')
                            ->label(__('order_edit.page.notify'))
                            ->helperText(fn (Get $get): ?string => filled($get('customer_email')) ? null : __('order_edit.page.notify_no_email')),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function deliveryOptions(): array
    {
        $rows = array_values(app(SalesSettings::class)->delivery_methods);
        $names = [];

        foreach ($rows as $row) {
            $name = trim((string) ($row['name'] ?? ''));

            if ($name !== '' && ($row['is_active'] ?? false)) {
                $names[$name] = $name;
            }
        }

        $current = $this->order()->delivery_method;

        if ($current !== null && $current !== '') {
            $names[$current] = $current;
        }

        return $names;
    }

    private function rowSum(mixed $qty, mixed $price): int
    {
        try {
            return (int) $qty * Money::fromRubles(is_scalar($price) ? (string) $price : null);
        } catch (InvalidArgumentException) {
            return 0;
        }
    }

    /**
     * @param  array<string, mixed>|null  $rows
     */
    private function itemsSum(?array $rows): int
    {
        return array_sum(array_map(fn (mixed $row): int => is_array($row) ? $this->rowSum($row['qty'] ?? 0, $row['price'] ?? 0) : 0, $rows ?? []));
    }

    /**
     * «Добавить товар»: a row with the price list price for the group of the order, or +1 on the row of the same unit.
     */
    public function addProduct(?string $key): void
    {
        $this->data['add_product'] = null;
        $parsed = $key === null ? null : OrderItemSearch::parse($key);

        if ($parsed === null) {
            return;
        }

        [$productId, $variantId] = $parsed;

        foreach ($this->data['items'] ?? [] as $rowKey => $row) {
            if ((int) ($row['product_id'] ?? 0) === $productId && ((int) ($row['variant_id'] ?? 0) ?: null) === $variantId) {
                $this->data['items'][$rowKey]['qty'] = min(QtyLimit::MAX, (int) $row['qty'] + 1);

                return;
            }
        }

        $editor = app(OrderEditor::class);
        $info = $editor->describeUnit($productId, $variantId);

        if ($info === null) {
            return;
        }

        $price = $editor->listPrice($this->order(), $productId, $variantId, 1);

        $this->data['items'][(string) Str::uuid()] = [
            'item_id' => null,
            'product_id' => $productId,
            'variant_id' => $variantId,
            'name' => $info['name'],
            'sku' => $info['sku'],
            'unit' => $info['unit'],
            'qty' => 1,
            'price' => Money::toRubles($price ?? 0),
            'stored_price' => null,
            'recalc' => false,
            'recalc_price' => null,
        ];
    }

    /**
     * «Пересчитать по прайсу» (OE-03): puts the price list price of the current quantity into every row and marks the
     * rows whose price changed.
     */
    public function recalculatePrices(): void
    {
        if (! $this->canEditPrices()) {
            return;
        }

        $editor = app(OrderEditor::class);
        $changed = 0;

        foreach ($this->data['items'] ?? [] as $rowKey => $row) {
            if (($row['product_id'] ?? null) === null) {
                continue;
            }

            $listed = $editor->listPrice($this->order(), (int) $row['product_id'], ($row['variant_id'] ?? null) === null ? null : (int) $row['variant_id'], max(1, (int) ($row['qty'] ?? 1)));

            if ($listed === null) {
                continue;
            }

            $this->data['items'][$rowKey]['price'] = Money::toRubles($listed);
            $this->data['items'][$rowKey]['recalc'] = true;
            $this->data['items'][$rowKey]['recalc_price'] = (string) $listed;

            if ($row['stored_price'] === null || Money::fromRubles((string) $row['stored_price']) !== $listed) {
                $changed++;
            }
        }

        Notification::make()->info()->title(__('order_edit.page.recalculated', ['count' => $changed]))->send();
    }

    /**
     * «Предпросмотр и сохранить»: validates the form, builds the plan on the server and opens the confirmation.
     */
    public function openPreview(): void
    {
        $user = $this->actor();

        if ($user === null) {
            return;
        }

        $data = $this->editData($this->form->getState());

        if ($data === null) {
            return;
        }

        try {
            $plan = $this->isInquiry()
                ? app(OrderEditor::class)->previewNewOrder($this->order(), $data, $user)
                : app(OrderEditor::class)->preview($this->order()->id, $data, $user);
        } catch (SalesException|AuthorizationException $e) {
            Notification::make()->danger()->title(__('order_edit.errors.preview_failed'))->body($e->getMessage())->send();

            return;
        }

        $this->preview = $this->present($plan);

        if (! $this->isInquiry() && ! $this->preview['changed']) {
            Notification::make()->info()->title(__('order_edit.preview.nothing'))->send();

            return;
        }

        $this->mountAction('confirmSave');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(EditPlan $plan): array
    {
        return app(EditPreviewPresenter::class)->present($plan, $this->isInquiry() ? OrderType::Order : $this->order()->type);
    }

    public function confirmSaveAction(): Action
    {
        return Action::make('confirmSave')
            ->label(__('order_edit.preview.save'))
            ->modalHeading(fn (): string => $this->isInquiry() ? __('order_edit.preview.inquiry_heading') : __('order_edit.preview.heading'))
            ->modalContent(fn (): View => view('filament.orders.edit-preview', ['preview' => $this->preview]))
            ->modalSubmitActionLabel(__('order_edit.preview.save'))
            ->modalCancelActionLabel(__('order_edit.preview.cancel'))
            ->modalWidth(Width::FiveExtraLarge)
            ->color('primary')
            ->action(fn () => $this->save());
    }

    /**
     * Saves the confirmed form. A conflict, a shortage or any broken rule keeps the form as it is.
     */
    public function save(): void
    {
        $user = $this->actor();
        $data = $this->editData($this->form->getState());

        if ($user === null || $data === null) {
            return;
        }

        try {
            if ($this->isInquiry()) {
                $order = app(OrderService::class)->createFromInquiry($this->order(), $this->version, $data, $user);

                Notification::make()->success()->title(__('order_edit.page.order_created', ['number' => $order->number]))->send();
                $this->redirect(OrderResource::getUrl('view', ['record' => $order]));

                return;
            }

            app(OrderEditor::class)->apply($this->order()->id, $this->version, $data, $user);
        } catch (ConcurrentEditException $e) {
            Notification::make()->danger()->persistent()->title(__('order_edit.errors.conflict_title'))->body($e->getMessage())->send();

            return;
        } catch (SalesException|AuthorizationException $e) {
            Notification::make()->danger()->persistent()->title(__('order_edit.errors.save_failed'))->body($e->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__('order_edit.page.saved'))->send();
        $this->redirect(OrderResource::getUrl('view', ['record' => $this->order()]));
    }

    /**
     * The form state as {@see EditData}; null (with a notification) when an amount cannot be read.
     *
     * @param  array<string, mixed>  $state
     */
    private function editData(array $state): ?EditData
    {
        $canPrices = $this->canEditPrices();
        $order = $this->order();

        try {
            $items = [];

            foreach ($state['items'] ?? [] as $row) {
                $price = $canPrices ? Money::fromRubles((string) ($row['price'] ?? '0')) : null;
                $recalc = $canPrices && filter_var($row['recalc'] ?? false, FILTER_VALIDATE_BOOLEAN)
                    && $price !== null && (string) $price === (string) ($row['recalc_price'] ?? '');
                $itemId = ($row['item_id'] ?? null) === null || $row['item_id'] === '' ? null : (int) $row['item_id'];

                $items[] = new EditItem(
                    $itemId,
                    $itemId === null && ($row['product_id'] ?? null) !== null ? (int) $row['product_id'] : null,
                    $itemId === null && ($row['variant_id'] ?? null) !== null && $row['variant_id'] !== '' ? (int) $row['variant_id'] : null,
                    (int) ($row['qty'] ?? 0),
                    $recalc ? null : $price,
                    $recalc,
                );
            }

            $reservation = $order->type === OrderType::Reservation;

            return new EditData(
                $items,
                (string) ($state['customer_name'] ?? ''),
                (string) ($state['customer_phone'] ?? ''),
                $state['customer_email'] ?? null,
                $reservation ? $order->delivery_method : ($state['delivery_method'] ?? null),
                $reservation ? $order->delivery_address : ($state['delivery_address'] ?? null),
                $reservation ? Money::fromRubles($order->delivery_price) : Money::fromRubles((string) ($state['delivery_price'] ?? '0')),
                $canPrices ? Money::fromRubles((string) ($state['manual_discount'] ?? '0')) : Money::fromRubles((string) $order->manual_discount),
                $state['customer_comment'] ?? null,
                $state['manager_comment'] ?? null,
                (bool) ($state['notify'] ?? false),
            );
        } catch (InvalidArgumentException) {
            Notification::make()->danger()->title(__('order_edit.errors.amount_invalid'))->send();

            return null;
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('order_edit.page.back'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => OrderResource::getUrl('view', ['record' => $this->order()])),
        ];
    }

    private function actor(): ?User
    {
        $user = Auth::user();

        return $user instanceof User ? $user : null;
    }
}
