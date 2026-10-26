<div @if ($anyActive) wire:poll.15s="refreshBuilds" @endif>
    {{-- ================= Builder settings ================= --}}
    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <div>
                <h4 class="header-title">Builder settings</h4>
                <p class="rd-card-sub mb-0">
                    Builds run on GitHub Actions in your rdgen fork. Inputs and installers travel on a temporary
                    draft release, so this console never has to be reachable from the internet.
                </p>
            </div>
            <div class="rd-card-actions">
                @if ($problems === [])
                    <span class="badge bg-success-subtle text-success me-2"><i class="ri-check-line"></i> Ready</span>
                @else
                    <span class="badge bg-warning-subtle text-warning me-2">Setup needed</span>
                @endif
                <button type="button" class="btn btn-light" wire:click="$toggle('showSettings')">
                    <i class="ri-settings-3-line"></i>{{ $showSettings ? 'Hide' : 'Edit' }}
                </button>
            </div>
        </div>

        @if ($showSettings)
            <form wire:submit="saveSettings" class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="cb-repo">rdgen repository <span class="text-danger">*</span></label>
                        <input id="cb-repo" type="text" class="form-control @error('repo') is-invalid @enderror"
                               wire:model="repo" placeholder="Servicell2026/rdgen" autocomplete="off">
                        @error('repo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="cb-branch">Branch</label>
                        <input id="cb-branch" type="text" class="form-control @error('branch') is-invalid @enderror"
                               wire:model="branch" autocomplete="off">
                        @error('branch') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="cb-token">GitHub token</label>
                        <input id="cb-token" type="password" class="form-control @error('token') is-invalid @enderror"
                               wire:model="token" autocomplete="new-password"
                               placeholder="{{ $hasToken ? 'Saved — leave blank to keep' : 'github_pat_…' }}">
                        @error('token') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">Fine-grained token, only for the rdgen repository:
                            <strong>Contents</strong> and <strong>Actions</strong> read and write.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="cb-zip">Zip password</label>
                        <input id="cb-zip" type="password" class="form-control @error('zipPassword') is-invalid @enderror"
                               wire:model="zipPassword" autocomplete="new-password"
                               placeholder="{{ $hasZipPassword ? 'Saved — leave blank to keep' : 'same as the ZIP_PASSWORD secret' }}">
                        @error('zipPassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">Exactly the value of the <code>ZIP_PASSWORD</code> secret in the repository.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="cb-host">Public server address</label>
                        <input id="cb-host" type="text" class="form-control @error('serverHost') is-invalid @enderror"
                               wire:model="serverHost" placeholder="{{ $effectiveHost ?: 'firma.myqnapcloud.com' }}" autocomplete="off">
                        @error('serverHost') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">The name clients use from other sites (DDNS or public IP). Blank: <code>{{ $effectiveHost ?: 'not set' }}</code>.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="cb-api">Public API address</label>
                        <input id="cb-api" type="text" class="form-control @error('apiServer') is-invalid @enderror"
                               wire:model="apiServer" placeholder="{{ $effectiveApi }}" autocomplete="off">
                        @error('apiServer') <div class="invalid-feedback">{{ $message }}</div> @enderror
                        <div class="form-text">Where clients reach this console's API (heartbeat, enrollment). Blank: <code>{{ $effectiveApi }}</code>.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="cb-company">Company name</label>
                        <input id="cb-company" type="text" class="form-control @error('company') is-invalid @enderror"
                               wire:model="company" placeholder="SERVICELL" autocomplete="off">
                        @error('company') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Server key</label>
                        <input type="text" class="form-control font-monospace" readonly value="{{ $publicKey ?: 'unknown — see Settings → Server' }}">
                    </div>
                </div>

                @if ($accessResult)
                    <div class="alert {{ $accessOk ? 'alert-success' : 'alert-warning' }} mt-3 mb-0">{{ $accessResult }}</div>
                @endif

                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <span wire:loading.remove wire:target="saveSettings">Save &amp; test</span>
                        <span wire:loading wire:target="saveSettings">Saving…</span>
                    </button>
                    <button type="button" class="btn btn-light" wire:click="testAccess">
                        <span wire:loading.remove wire:target="testAccess">Test connection</span>
                        <span wire:loading wire:target="testAccess">Testing…</span>
                    </button>
                </div>
            </form>
        @endif
    </div>

    {{-- ================= New build ================= --}}
    <div class="card">
        <div class="card-header">
            <h4 class="header-title">New Windows build</h4>
            <p class="rd-card-sub mb-0">Server, key and API address are filled in from the settings above.</p>
        </div>
        <form wire:submit="build" class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label" for="b-label">Label <span class="text-danger">*</span></label>
                    <input id="b-label" type="text" class="form-control @error('label') is-invalid @enderror"
                           wire:model="label" placeholder="SERVICELL Remote — Firma X">
                    @error('label') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="b-app">App name <span class="text-danger">*</span></label>
                    <input id="b-app" type="text" class="form-control @error('appName') is-invalid @enderror"
                           wire:model="appName" placeholder="SERVICELL Remote">
                    @error('appName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="b-exe">File name <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input id="b-exe" type="text" class="form-control @error('exeName') is-invalid @enderror"
                               wire:model="exeName" placeholder="servicell-remote">
                        <span class="input-group-text">.exe</span>
                        @error('exeName') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="b-ver">RustDesk version</label>
                    <input id="b-ver" type="text" class="form-control @error('version') is-invalid @enderror" wire:model="version">
                    @error('version') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-8">
                    <label class="form-label" for="b-key">Enrollment key</label>
                    <select id="b-key" class="form-select @error('enrollmentKeyId') is-invalid @enderror" wire:model.live="enrollmentKeyId">
                        <option value="">None — devices are not assigned to an account</option>
                        @foreach ($keys as $k)
                            <option value="{{ $k->id }}">{{ $k->name }} → {{ $k->user?->username }}</option>
                        @endforeach
                    </select>
                    @error('enrollmentKeyId') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-text">Computers that install this build land in that account automatically.
                        <a href="{{ route('enrollment-keys') }}">Manage keys</a></div>
                </div>

                <div class="col-md-6">
                    <label class="form-label" for="b-icon">Icon (PNG, square, 256×256 or larger)</label>
                    <input id="b-icon" type="file" accept="image/png" class="form-control @error('icon') is-invalid @enderror" wire:model="icon">
                    @error('icon') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    @if ($icon && ! $errors->has('icon'))
                        <img src="{{ $icon->temporaryUrl() }}" alt="" class="mt-2 rounded" style="width:64px;height:64px;object-fit:contain;">
                    @elseif ($iconFrom)
                        <div class="form-text">Keeping the icon of the build you are repeating.</div>
                    @else
                        <div class="form-text">Blank: the standard RustDesk icon.</div>
                    @endif
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="b-logo">Logo (PNG, shown in the main window)</label>
                    <input id="b-logo" type="file" accept="image/png" class="form-control @error('logo') is-invalid @enderror" wire:model="logo">
                    @error('logo') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    @if ($logo && ! $errors->has('logo'))
                        <img src="{{ $logo->temporaryUrl() }}" alt="" class="mt-2 rounded" style="max-height:64px;">
                    @elseif ($logoFrom)
                        <div class="form-text">Keeping the logo of the build you are repeating.</div>
                    @endif
                </div>

                <div class="col-md-4">
                    <label class="form-label" for="b-dir">Connections</label>
                    <select id="b-dir" class="form-select" wire:model="direction">
                        <option value="both">Both ways</option>
                        <option value="incoming">Incoming only (customer PCs)</option>
                        <option value="outgoing">Outgoing only (technicians)</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="b-approve">Accept a session by</label>
                    <select id="b-approve" class="form-select" wire:model="approveMode">
                        <option value="password-click">Password or click</option>
                        <option value="password">Password only</option>
                        <option value="click">Click only</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="b-theme">Theme</label>
                    <select id="b-theme" class="form-select" wire:model="theme">
                        <option value="system">Follow Windows</option>
                        <option value="light">Light</option>
                        <option value="dark">Dark</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label" for="b-pass">Permanent password</label>
                    <input id="b-pass" type="password" class="form-control @error('permanentPassword') is-invalid @enderror"
                           wire:model="permanentPassword" autocomplete="new-password" placeholder="Optional — for unattended access">
                    @error('permanentPassword') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    <div class="form-text">Baked into every install of this build. Leave blank to set it per PC.</div>
                </div>
                <div class="col-md-6">
                    <div class="form-check mt-md-4">
                        <input class="form-check-input" type="checkbox" id="b-hidecm" wire:model="hideCm">
                        <label class="form-check-label" for="b-hidecm">Hide the connection window (permanent password only)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="b-noset" wire:model="disableSettings">
                        <label class="form-check-label" for="b-noset">Lock the settings</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="b-noinst" wire:model="disableInstallation">
                        <label class="form-check-label" for="b-noinst">Run without installing (portable only)</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="b-noupd" wire:model="removeUpdateNotice">
                        <label class="form-check-label" for="b-noupd">Hide the “new version available” notice</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="b-pub" wire:model="publish" @disabled($enrollmentKeyId)>
                        <label class="form-check-label" for="b-pub">Publish on the public download page
                            @if ($enrollmentKeyId) <span class="text-muted">(never for a build with a key)</span> @endif
                        </label>
                    </div>
                </div>
            </div>

            <div class="mt-3">
                <button type="submit" class="btn btn-primary" @disabled($problems !== [])>
                    <span wire:loading.remove wire:target="build"><i class="ri-hammer-line me-1"></i>Build</span>
                    <span wire:loading wire:target="build">Starting the build…</span>
                </button>
                @if ($problems !== [])
                    <span class="text-warning ms-2 fs-13">Finish the builder settings first.</span>
                @endif
            </div>
        </form>
    </div>

    {{-- ================= Builds ================= --}}
    <div class="card">
        <div class="card-header">
            <h4 class="header-title">Builds</h4>
            <p class="rd-card-sub mb-0">A build usually takes 30–60 minutes. Finished installers appear in
                <a href="{{ route('client-downloads') }}">Client Downloads</a>.</p>
        </div>
        <div class="table-responsive">
            <table class="table table-hover table-centered mb-0">
                <thead>
                <tr>
                    <th>Build</th>
                    <th class="d-none d-md-table-cell">Account</th>
                    <th>Status</th>
                    <th class="d-none d-md-table-cell">Started</th>
                    <th class="text-end">Action</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($builds as $b)
                    @php
                        $tone = match ($b->status) { 'done' => 'success', 'failed' => 'danger', default => 'info' };
                    @endphp
                    <tr wire:key="cb{{ $b->id }}">
                        <td>
                            <span class="rd-cell-title">#{{ $b->id }} {{ $b->label }}</span>
                            <span class="rd-cell-sub d-block">{{ $b->app_name }} · {{ $b->exe_name }}.exe · RustDesk {{ $b->rustdesk_version }}</span>
                        </td>
                        <td class="d-none d-md-table-cell">{{ $b->enrollmentKey?->user?->username ?? '—' }}</td>
                        <td style="max-width:360px">
                            <span class="badge bg-{{ $tone }}-subtle text-{{ $tone }}">
                                @if ($b->isActive()) <span class="spinner-border spinner-border-sm me-1" style="width:.7rem;height:.7rem"></span> @endif
                                {{ ucfirst($b->status) }}
                            </span>
                            @if ($b->message)
                                <span class="rd-cell-sub d-block text-wrap">{{ $b->message }}</span>
                            @endif
                            @if ($b->github_run_url)
                                <a class="fs-12" href="{{ $b->github_run_url }}" target="_blank" rel="noopener">GitHub log <i class="ri-external-link-line"></i></a>
                            @endif
                        </td>
                        <td class="d-none d-md-table-cell">
                            <span title="{{ $b->created_at }}">{{ $b->created_at->diffForHumans() }}</span>
                        </td>
                        <td class="text-end rd-rowact text-nowrap">
                            @if ($b->status === 'done')
                                @foreach ($b->downloads() as $d)
                                    <a href="{{ route('downloads.show', $d) }}">{{ strtoupper(pathinfo($d->original_name, PATHINFO_EXTENSION)) }}</a>
                                @endforeach
                            @endif
                            @if ($b->isActive())
                                <a href="javascript:void(0);" class="text-warning" wire:click="cancel({{ $b->id }})"
                                   wire:confirm="Cancel build #{{ $b->id }}?">Cancel</a>
                            @else
                                <a href="javascript:void(0);" wire:click="buildAgain({{ $b->id }})">Again</a>
                                <a href="javascript:void(0);" class="text-danger" wire:click="deleteBuild({{ $b->id }})"
                                   wire:confirm="Remove build #{{ $b->id }} from this list? The installers stay in Client Downloads.">Remove</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="rd-empty-cell">
                            <div class="rd-empty">
                                <div class="rd-empty-icon"><i class="ri-hammer-line"></i></div>
                                <p class="rd-empty-title">No builds yet.</p>
                            </div>
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
