{{-- VR-09: one block per axis of the card: colour swatches, buttons or a drop-down list. Alpine `variantPicker` (resources/js/app.js) reads the JSON of #variant-data, nothing is requested on selection. A combination without stock is shaded but stays selectable and then reads «нет в наличии». --}}
<div class="mt-4 space-y-4" data-variant-picker>
    <template x-for="axis in axes" :key="axis.id">
        <fieldset :data-axis="axis.id">
            <legend class="mb-1 text-sm font-medium">
                <span x-text="axis.name"></span>:
                <span class="font-normal text-slate-600" x-text="valueText(axis.id)"></span>
            </legend>

            <template x-if="axis.display === 'select'">
                <select :aria-label="axis.name" @change="pick(axis.id, Number($event.target.value))"
                        class="min-h-11 w-full max-w-xs rounded border border-slate-300 px-3 py-2 focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary">
                    <template x-for="value in axis.values" :key="value.id">
                        <option :value="value.id" :selected="selection[axis.id] === value.id" x-text="value.text + (shaded(axis.id, value.id) ? ' — ' + labels.unavailable : '')"></option>
                    </template>
                </select>
            </template>

            <template x-if="axis.display !== 'select'">
                <div class="flex flex-wrap gap-2" role="group" :aria-label="axis.name">
                    <template x-for="value in axis.values" :key="value.id">
                        <button type="button" @click="pick(axis.id, value.id)"
                                :aria-pressed="(selection[axis.id] === value.id).toString()"
                                :data-value="value.id"
                                :title="value.text + (shaded(axis.id, value.id) ? ' — ' + labels.unavailable : '')"
                                :aria-label="value.text + (shaded(axis.id, value.id) ? ' — ' + labels.unavailable : '')"
                                :class="[
                                    selection[axis.id] === value.id ? 'border-primary ring-2 ring-primary' : 'border-slate-300 hover:border-slate-500',
                                    shaded(axis.id, value.id) ? 'opacity-40' : '',
                                    axis.display === 'color' ? 'h-11 w-11 rounded-full border bg-cover bg-center' : 'min-h-11 min-w-11 rounded border px-3 py-2 text-sm',
                                ]"
                                :style="axis.display === 'color' ? swatch(value) : ''"
                                class="relative focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary">
                            <span x-show="axis.display !== 'color'" x-text="value.text"></span>
                            <span x-show="axis.display === 'color'" class="sr-only" x-text="value.text"></span>
                        </button>
                    </template>
                </div>
            </template>
        </fieldset>
    </template>
</div>
