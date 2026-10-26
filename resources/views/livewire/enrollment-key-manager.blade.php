<div>
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <div>
                <h4 class="header-title">Enrollment Keys</h4>
                <p class="rd-card-sub mb-0">
                    A key ties a client build to one account. Any computer that installs a build carrying the key
                    appears in that account, approved, with no sign-in. The key can only add devices to its own
                    account — revoke it at any time and new installs stop enrolling.
                </p>
            </div>
            <div class="rd-card-actions">
                <button type="button" class="btn btn-primary" wire:click="create">
                    <i class="ri-add-line"></i>New Key
                </button>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-hover table-centered mb-0">
                <thead>
                <tr>
                    <th>Key</th>
                    <th>Account</th>
                    <th class="d-none d-md-table-cell">Device group</th>
                    <th>Devices</th>
                    <th class="d-none d-md-table-cell">Last used</th>
                    <th>Status</th>
                    <th class="text-end">Action</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($keys as $key)
                    @php
                        $status = $key->statusLabel();
                        $tone = match ($status) { 'Active' => 'success', 'Full' => 'warning', default => 'danger' };
                    @endphp
                    <tr wire:key="ek{{ $key->id }}">
                        <td>
                            <div class="rd-cell rd-tone-amber">
                                <span class="rd-avatar"><i class="ri-key-line"></i></span>
                                <div class="min-width-0">
                                    <span class="rd-cell-title">{{ $key->name }}</span>
                                    <span class="rd-cell-sub rd-mono">{{ $key->token_prefix }}…</span>
                                </div>
                            </div>
                        </td>
                        <td>
                            @if ($key->user)
                                <a href="{{ route('users.show', $key->user) }}">{{ $key->user->username }}</a>
                            @else
                                —
                            @endif
                        </td>
                        <td class="d-none d-md-table-cell">{{ $key->deviceGroup?->name ?? '—' }}</td>
                        <td>{{ $key->devices_count }}{{ $key->max_devices ? ' / '.$key->max_devices : '' }}</td>
                        <td class="d-none d-md-table-cell">
                            @if ($key->last_used_at)
                                <span title="{{ $key->last_used_at }}">{{ $key->last_used_at->diffForHumans() }}</span>
                            @else
                                <span class="text-muted">Never</span>
                            @endif
                        </td>
                        <td>
                            <span class="badge bg-{{ $tone }}-subtle text-{{ $tone }}">{{ $status }}</span>
                            @if ($key->expires_at && ! $key->isExpired())
                                <span class="rd-cell-sub d-block">until {{ $key->expires_at->format('Y-m-d') }}</span>
                            @endif
                        </td>
                        <td class="text-end rd-rowact text-nowrap">
                            <a href="javascript:void(0);" wire:click="reveal({{ $key->id }})">
                                {{ $revealed === $key->id ? 'Hide' : 'Show' }}
                            </a>
                            @if ($key->isRevoked())
                                <a href="javascript:void(0);" wire:click="restore({{ $key->id }})">Enable</a>
                            @else
                                <a href="javascript:void(0);" class="text-warning" wire:click="revoke({{ $key->id }})"
                                   wire:confirm="Revoke “{{ $key->name }}”? Devices already enrolled stay where they are; new installs stop enrolling.">Revoke</a>
                            @endif
                            <a href="javascript:void(0);" class="text-danger" wire:click="deleteKey({{ $key->id }})"
                               wire:confirm="Delete “{{ $key->name }}”? Enrolled devices keep their account. Client builds carrying this key stop enrolling.">Delete</a>
                        </td>
                    </tr>
                    @if ($revealed === $key->id)
                        <tr wire:key="ekr{{ $key->id }}">
                            <td colspan="7" class="bg-body-tertiary">
                                <label class="form-label mb-1">Key</label>
                                <div class="input-group input-group-sm mb-2">
                                    <input type="text" class="form-control font-monospace" readonly value="{{ $key->plaintext() }}">
                                    <button class="btn btn-light" type="button" onclick="rdCopyPrevious(this)" title="Copy">
                                        <i class="ri-file-copy-line"></i>
                                    </button>
                                </div>
                                <label class="form-label mb-1">Client setting (rdgen → Override settings)</label>
                                <div class="input-group input-group-sm">
                                    <input type="text" class="form-control font-monospace" readonly value="{{ $key->clientSettingLine() }}">
                                    <button class="btn btn-light" type="button" onclick="rdCopyPrevious(this)" title="Copy">
                                        <i class="ri-file-copy-line"></i>
                                    </button>
                                </div>
                                <div class="form-text">
                                    Easiest: pick this key in <a href="{{ route('client-builder') }}">Client Builder</a> and
                                    the build carries it for you.
                                </div>
                            </td>
                        </tr>
                    @endif
                @empty
                    <tr>
                        <td colspan="7" class="rd-empty-cell">
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="ri-key-line"></i></div>
                                <p class="rd-empty-title">No enrollment keys yet.</p>
                                <p class="rd-empty-text">Create one per customer account, then build that customer's client with it.</p>
                                <button type="button" class="btn btn-sm btn-outline-light" wire:click="create">New Key</button>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>

    @if ($showModal)
        <div class="modal fade show d-block" tabindex="-1" role="dialog" aria-modal="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <form wire:submit="save">
                        <div class="modal-header">
                            <h5 class="modal-title">New Enrollment Key</h5>
                            <button type="button" class="btn-close" wire:click="closeModal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label" for="ek-user">Account <span class="text-danger">*</span></label>
                                <select id="ek-user" class="form-select @error('userId') is-invalid @enderror" wire:model="userId">
                                    <option value="">— choose —</option>
                                    @foreach ($users as $u)
                                        <option value="{{ $u->id }}">{{ $u->username }}{{ $u->name ? ' ('.$u->name.')' : '' }}</option>
                                    @endforeach
                                </select>
                                @error('userId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <div class="form-text">Enrolled devices are owned by this user. Create the user first under Users.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="ek-name">Name <span class="text-danger">*</span></label>
                                <input type="text" id="ek-name" class="form-control @error('name') is-invalid @enderror"
                                       wire:model="name" placeholder="e.g. Firma X — sediu" autocomplete="off">
                                @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-3">
                                <label class="form-label" for="ek-group">Device group</label>
                                <select id="ek-group" class="form-select @error('deviceGroupId') is-invalid @enderror" wire:model="deviceGroupId">
                                    <option value="">— none —</option>
                                    @foreach ($groups as $g)
                                        <option value="{{ $g->id }}">{{ $g->name }}</option>
                                    @endforeach
                                </select>
                                @error('deviceGroupId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="row g-2">
                                <div class="col-6">
                                    <label class="form-label" for="ek-max">Device limit</label>
                                    <input type="number" id="ek-max" min="1" class="form-control @error('maxDevices') is-invalid @enderror"
                                           wire:model="maxDevices" placeholder="No limit">
                                    @error('maxDevices') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                                <div class="col-6">
                                    <label class="form-label" for="ek-exp">Expires in (days)</label>
                                    <input type="number" id="ek-exp" min="1" class="form-control @error('expiresDays') is-invalid @enderror"
                                           wire:model="expiresDays" placeholder="Never">
                                    @error('expiresDays') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-light" wire:click="closeModal">Cancel</button>
                            <button type="submit" class="btn btn-primary">
                                <span wire:loading.remove wire:target="save"><i class="ri-key-line me-1"></i>Create Key</span>
                                <span wire:loading wire:target="save">Creating…</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal-backdrop fade show"></div>
    @endif
</div>
