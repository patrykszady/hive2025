<input
    type="text"
    wire:model.live="payments.{{$index}}.description"
    name="payments.{{$index}}.description"
    id="payments.{{$index}}.description"
    autocomplete="payments.{{$index}}.description"
    placeholder="Due demo day"
    class="flex-1 block w-full min-w-0 placeholder-gray-200 border-gray-300 rounded-md sm:text-sm hover:bg-gray-50 focus:ring-indigo-500 focus:border-indigo-500"
>
