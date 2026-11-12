<native:column>
    <native:error-probe native:model="name" @change="check" />
    <native:error-probe @change="checkWith({{ $marker }})" native:model.debounce.300ms="email" />
    <native:error-probe @change="checkNickname" @model="nickname" />
</native:column>
