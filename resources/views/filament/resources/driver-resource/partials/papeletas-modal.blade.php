<div class="flex overflow-x-auto gap-4 p-4">
    @foreach($imagenes as $img)
        <img src="{{ Storage::url($img) }}" alt="Papeleta" class="flex-shrink-0 w-40 h-40 object-cover rounded shadow">
    @endforeach
</div>
