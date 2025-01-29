<span class="inline-flex rounded-md shadow-sm">
    <span class="inline-flex items-center px-2 py-2 bg-white border border-gray-300 rounded-l-md">
        <label for="include_reimbursement" class="sr-only">Select all</label>
        <input
            wire:model.live="include_reimbursement"
            id="include_reimbursement"
            type="checkbox"
            name="include_reimbursement"
            class="w-4 h-4 text-indigo-600 border-gray-300 rounded focus:ring-indigo-600"
        >
    </span>
    <label for="checkbox-message" class="sr-only">Select message type</label>
    <input
        type="text"
        id="checkbox-message"
        value="Include in Estimate"
        name="checkbox-message"
        disabled
        class="-ml-px block w-full rounded-l-none rounded-r-md border-0 bg-gray-50 py-1.5 pl-3 pr-9 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-inset focus:ring-indigo-600 sm:text-sm sm:leading-6"
    >
</span>
