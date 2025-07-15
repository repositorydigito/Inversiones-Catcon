<div class="flex flex-wrap gap-4">
    @foreach($imagenes as $img)
        <img src="{{ Storage::url($img) }}" alt="Papeleta" class="w-40 h-40 object-cover rounded shadow">
    @endforeach
</div>
