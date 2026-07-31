<native:top-bar title="Tasks" />
<native:scroll-view class="w-full h-full">
    @foreach ($tasks as $task)
        <native:column class="w-full p-4 bg-white rounded-xl">
            <native:text>{{ $task }}</native:text>
        </native:column>
    @endforeach
</native:scroll-view>
<native:fab icon="add" ref="add-task" @tap="create" />
