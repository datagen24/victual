<ul class="list-unstyled ml-3">
@foreach($permissionRows as $permission)
@if($permission->parent == ($parentId ?? null))
<li>
	<label title="{{ isset($roles) ? implode(', ', array_map(function ($code) use ($roles) { foreach ($roles as $role) { if ($role->code === $code) return $role->name; } return $code; }, explode(',', $permission->via_roles ?? ''))) : '' }}">
		<input type="checkbox" class="permission-cb" data-perm-id="{{ $permission->permission_id }}"
			data-parent-id="{{ $permission->parent }}" data-permission-name="{{ $permission->permission_name }}"
			data-direct="{{ in_array((int)$permission->permission_id, $directIds) ? '1' : '0' }}"
			data-inherited="{{ empty($permission->via_roles) ? '0' : '1' }}"
			@if(!$canEdit) disabled @endif>
		{{ $__t($permission->permission_name) }}
		@if(!empty($permission->via_roles)) <small class="text-muted">({{ $permission->via_roles }})</small> @endif
	</label>
	@include('components.permission_tree', ['parentId' => $permission->permission_id])
</li>
@endif
@endforeach
</ul>
