<?php

declare(strict_types=1);

namespace NoraTailwindDashboard\Reactive;

use Nora\Reactive\Component;
use Nora\Reactive\Components\ReactiveForm;
use Nora\Reactive\ReactiveAction;
use Nora\Reactive\SafeHtml;
use Nora\Reactive\State;
use Nora\Reactive\UI;

final class DashboardPage extends Component
{
    public function initialState(): array
    {
        return [
            'dark' => false,
            'query' => '',
            'refreshes' => 0,
            'profile_saved' => false,
            'next_id' => 5,
            'members' => [
                ['id' => 1, 'name' => 'Amina El Idrissi', 'email' => 'amina@example.test', 'role' => 'Admin', 'status' => 'Active'],
                ['id' => 2, 'name' => 'Youssef Benali', 'email' => 'youssef@example.test', 'role' => 'Editor', 'status' => 'Active'],
                ['id' => 3, 'name' => 'Salma Amrani', 'email' => 'salma@example.test', 'role' => 'Viewer', 'status' => 'Invited'],
                ['id' => 4, 'name' => 'Omar Haddad', 'email' => 'omar@example.test', 'role' => 'Editor', 'status' => 'Active'],
            ],
        ];
    }

    protected function view(State $state): SafeHtml
    {
        $dark = $state->get('dark');
        $query = strtolower(trim($state->get('query')));
        $members = array_values(array_filter(
            $state->get('members'),
            static fn (array $member): bool => $query === ''
                || str_contains(strtolower($member['name']), $query)
                || str_contains(strtolower($member['email']), $query),
        ));

        return UI::element('div', [
            'class' => ($dark ? 'dark ' : '')
                . 'min-h-screen bg-slate-50 text-slate-900 transition-colors dark:bg-slate-950 dark:text-slate-100',
        ], [
            UI::element('div', ['class' => 'mx-auto flex min-h-screen max-w-[1600px]'], [
                $this->sidebar(),
                UI::element('div', ['class' => 'min-w-0 flex-1'], [
                    $this->header($state),
                    UI::element('main', ['class' => 'mx-auto max-w-7xl space-y-8 px-4 py-8 sm:px-6 lg:px-8'], [
                        $this->pageHeading($state),
                        $this->stats(),
                        $this->directory($members, $query),
                        $this->profileForm($state),
                        $this->tabs(),
                        $this->feedbackStates($state),
                    ]),
                ]),
            ]),
        ]);
    }

    public function actions(): array
    {
        return [
            'filter-members' => ReactiveAction::publicAction(
                ['query' => 'string|max:80'],
                static fn (array $state, array $arguments): array => [
                    ...$state,
                    'query' => trim($arguments['query']),
                ],
            ),
            'refresh-metrics' => ReactiveAction::publicAction(
                [],
                static fn (array $state): array => [
                    ...$state,
                    'refreshes' => $state['refreshes'] + 1,
                ],
            ),
            'toggle-theme' => ReactiveAction::publicAction(
                [],
                static fn (array $state): array => [
                    ...$state,
                    'dark' => !$state['dark'],
                ],
            ),
            'save-profile' => ReactiveAction::publicAction(
                [
                    'name' => 'required|string|min:2|max:80',
                    'email' => 'required|string|email|max:254',
                ],
                static fn (array $state): array => [...$state, 'profile_saved' => true],
            ),
            'add-member' => ReactiveAction::publicAction(
                [
                    'name' => 'required|string|min:2|max:80',
                    'email' => 'required|string|email|max:254',
                    'role' => 'required|string|max:20',
                ],
                static function (array $state, array $arguments): array {
                    $members = $state['members'];
                    $members[] = [
                        'id' => $state['next_id'],
                        'name' => trim($arguments['name']),
                        'email' => trim($arguments['email']),
                        'role' => $arguments['role'],
                        'status' => 'Invited',
                    ];

                    return [
                        ...$state,
                        'members' => $members,
                        'next_id' => $state['next_id'] + 1,
                    ];
                },
            ),
        ];
    }

    private function sidebar(): SafeHtml
    {
        $linkClass = 'flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-slate-600 '
            . 'transition hover:bg-slate-100 hover:text-slate-950 dark:text-slate-300 dark:hover:bg-slate-800 dark:hover:text-white';

        return UI::element('aside', [
            'class' => 'hidden w-64 shrink-0 border-r border-slate-200 bg-white p-5 dark:border-slate-800 '
                . 'dark:bg-slate-900 lg:flex lg:flex-col',
            'aria-label' => 'Main navigation',
        ], [
            UI::element('a', ['href' => '/', 'class' => 'flex items-center gap-3 px-2 py-3'], [
                UI::element('span', [
                    'class' => 'grid size-10 place-items-center rounded-xl bg-indigo-600 text-lg font-bold text-white',
                    'aria-hidden' => true,
                ], 'N'),
                UI::element('span', [], [
                    UI::element('span', ['class' => 'block font-semibold tracking-tight text-slate-950 dark:text-white'], 'Nora Studio'),
                    UI::element('span', ['class' => 'block text-xs text-slate-500 dark:text-slate-400'], 'Workspace'),
                ]),
            ]),
            UI::element('p', ['class' => 'mb-2 mt-9 px-3 text-xs font-semibold uppercase tracking-wider text-slate-400'], 'Workspace'),
            UI::element('nav', ['aria-label' => 'Workspace', 'class' => 'space-y-1'], [
                UI::element('a', ['href' => '#overview', 'aria-current' => 'page', 'class' => $linkClass . ' bg-indigo-50 text-indigo-700 dark:bg-indigo-950 dark:text-indigo-300'], 'Overview'),
                UI::element('a', ['href' => '#directory', 'class' => $linkClass], 'People'),
                UI::element('a', ['href' => '#settings', 'class' => $linkClass], 'Settings'),
            ]),
            UI::element('div', ['class' => 'mt-auto rounded-xl border border-slate-200 bg-slate-50 p-4 dark:border-slate-700 dark:bg-slate-800'], [
                UI::element('p', ['class' => 'text-sm font-semibold'], 'Need a hand?'),
                UI::element('p', ['class' => 'mt-1 text-xs leading-5 text-slate-500 dark:text-slate-400'], 'Explore the Nora PHP-first component and reactive APIs.'),
                UI::element('a', ['href' => 'https://github.com/Yasser-devb/nora', 'class' => 'mt-3 inline-block text-sm font-medium text-indigo-700 hover:underline dark:text-indigo-300'], 'View the framework'),
            ]),
        ]);
    }

    private function header(State $state): SafeHtml
    {
        return UI::element('header', [
            'class' => 'sticky top-0 z-10 flex min-h-16 flex-wrap items-center justify-between gap-4 border-b border-slate-200 '
                . 'bg-white/90 px-4 backdrop-blur sm:px-6 dark:border-slate-800 dark:bg-slate-900/90 lg:px-8',
        ], [
            UI::element('p', ['class' => 'font-semibold lg:hidden'], 'Nora Studio'),
            UI::element('form', ['role' => 'search', 'action' => '#directory', 'class' => 'hidden w-full max-w-md sm:block'], [
                UI::element('label', ['class' => 'sr-only', 'for' => 'header-search'], 'Search this workspace'),
                UI::element('input', [
                    'id' => 'header-search',
                    'type' => 'search',
                    'placeholder' => 'Search this workspace',
                    'class' => 'w-full rounded-lg border border-slate-300 bg-slate-50 px-3 py-2 text-sm outline-none placeholder:text-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 dark:border-slate-700 dark:bg-slate-950 dark:focus:ring-indigo-900',
                ]),
            ]),
            UI::element('div', ['class' => 'ml-auto flex items-center gap-2 sm:gap-4'], [
                UI::actionButton('toggle-theme', $state->get('dark') ? 'Light mode' : 'Dark mode', [], [
                    'class' => 'rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-indigo-500 dark:border-slate-700 dark:hover:bg-slate-800',
                    'aria-label' => $state->get('dark') ? 'Switch to light mode' : 'Switch to dark mode',
                ]),
                UI::element('button', [
                    'type' => 'button',
                    'class' => 'grid size-9 place-items-center rounded-full bg-indigo-100 text-sm font-semibold text-indigo-800 dark:bg-indigo-900 dark:text-indigo-200',
                    'aria-label' => 'Account menu for Amina El Idrissi',
                ], 'AE'),
            ]),
            UI::element('nav', [
                'aria-label' => 'Mobile workspace',
                'class' => 'flex w-full gap-5 border-t border-slate-200 py-2 text-sm font-medium text-slate-600 lg:hidden dark:border-slate-800 dark:text-slate-300',
            ], [
                UI::element('a', ['href' => '#overview'], 'Overview'),
                UI::element('a', ['href' => '#directory'], 'People'),
                UI::element('a', ['href' => '#settings'], 'Settings'),
            ]),
        ]);
    }

    private function pageHeading(State $state): SafeHtml
    {
        return UI::element('section', [
            'id' => 'overview',
            'class' => 'flex flex-col justify-between gap-4 sm:flex-row sm:items-end',
        ], [
            UI::element('div', [], [
                UI::element('p', ['class' => 'text-sm font-medium text-indigo-700 dark:text-indigo-300'], 'Wednesday, October 29'),
                UI::element('h1', ['class' => 'mt-2 text-3xl font-bold tracking-tight sm:text-4xl'], 'Good morning, Amina'),
                UI::element('p', ['class' => 'mt-2 text-sm text-slate-500 dark:text-slate-400'], 'Here is what is happening in your workspace today.'),
            ]),
            UI::actionButton('refresh-metrics', 'Refresh metrics', [], [
                'class' => 'inline-flex items-center justify-center rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-slate-950',
            ]),
        ]);
    }

    private function stats(): SafeHtml
    {
        $stats = [
            ['label' => 'Total members', 'value' => '2,408', 'change' => '+12.8%', 'tone' => 'text-emerald-700 dark:text-emerald-300'],
            ['label' => 'Active projects', 'value' => '18', 'change' => '+3 this month', 'tone' => 'text-indigo-700 dark:text-indigo-300'],
            ['label' => 'Tasks completed', 'value' => '84.6%', 'change' => '+4.2%', 'tone' => 'text-emerald-700 dark:text-emerald-300'],
            ['label' => 'Pending invites', 'value' => '7', 'change' => 'Needs attention', 'tone' => 'text-amber-700 dark:text-amber-300'],
        ];

        return UI::element('section', ['class' => 'grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4', 'aria-label' => 'Workspace statistics'],
            array_map(static fn (array $stat): SafeHtml => UI::element('article', [
                'class' => 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900',
            ], [
                UI::element('p', ['class' => 'text-sm font-medium text-slate-500 dark:text-slate-400'], $stat['label']),
                UI::element('p', ['class' => 'mt-3 text-3xl font-bold tracking-tight'], $stat['value']),
                UI::element('p', ['class' => 'mt-2 text-xs font-semibold ' . $stat['tone']], $stat['change']),
            ]), $stats),
        );
    }

    /**
     * @param list<array{id: int, name: string, email: string, role: string, status: string}> $members
     */
    private function directory(array $members, string $query): SafeHtml
    {
        $rows = UI::each(
            $members,
            static fn (array $member): int => $member['id'],
            static fn (array $member): SafeHtml => UI::element('tr', [
                'class' => 'border-t border-slate-100 dark:border-slate-800',
            ], [
                UI::element('td', ['class' => 'whitespace-nowrap px-4 py-4 font-medium'], $member['name']),
                UI::element('td', ['class' => 'whitespace-nowrap px-4 py-4 text-slate-500 dark:text-slate-400'], $member['email']),
                UI::element('td', ['class' => 'whitespace-nowrap px-4 py-4 text-slate-600 dark:text-slate-300'], $member['role']),
                UI::element('td', ['class' => 'whitespace-nowrap px-4 py-4'], [
                    UI::element('span', [
                        'class' => $member['status'] === 'Active'
                            ? 'rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950 dark:text-emerald-300'
                            : 'rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-950 dark:text-amber-300',
                    ], $member['status']),
                ]),
                UI::element('td', ['class' => 'whitespace-nowrap px-4 py-4 text-right text-sm text-slate-500'], 'View'),
            ]),
        );

        return UI::element('section', [
            'id' => 'directory',
            'class' => 'overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm dark:border-slate-800 dark:bg-slate-900',
        ], [
            UI::element('div', ['class' => 'flex flex-col gap-4 border-b border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between dark:border-slate-800'], [
                UI::element('div', [], [
                    UI::element('h2', ['class' => 'text-lg font-semibold'], 'People'),
                    UI::element('p', ['class' => 'mt-1 text-sm text-slate-500 dark:text-slate-400'], 'Manage access to your workspace.'),
                ]),
                UI::element('div', ['class' => 'flex flex-col gap-2 sm:flex-row'], [
                    ReactiveForm::render(['action_name' => 'filter-members'], UI::element('input', [
                        'type' => 'search',
                        'name' => 'query',
                        'value' => $query,
                        'placeholder' => 'Search people',
                        'aria-label' => 'Search people',
                        'data-nora-field' => 'query',
                        'class' => 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 sm:w-56 dark:border-slate-700 dark:bg-slate-950 dark:focus:ring-indigo-900',
                    ]), ['class' => 'flex gap-2 aria-busy:opacity-60']),
                    UI::element('button', [
                        'type' => 'button',
                        'data-nora-modal-open' => 'invite-modal',
                        'class' => 'rounded-lg bg-indigo-600 px-3 py-2 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500',
                    ], 'Invite person'),
                ]),
            ]),
            $members === []
                ? UI::element('div', ['class' => 'p-12 text-center'], [
                    UI::element('h3', ['class' => 'font-semibold'], 'No people found'),
                    UI::element('p', ['class' => 'mt-1 text-sm text-slate-500 dark:text-slate-400'], 'Try another name or email address.'),
                ])
                : UI::element('div', ['class' => 'overflow-x-auto'], [
                    UI::element('table', ['class' => 'w-full min-w-[680px] text-left text-sm'], [
                        UI::element('caption', ['class' => 'sr-only'], 'People in the workspace'),
                        UI::element('thead', ['class' => 'bg-slate-50 text-xs uppercase tracking-wide text-slate-500 dark:bg-slate-800/60 dark:text-slate-400'], [
                            UI::element('tr', [], array_map(
                                static fn (string $heading): SafeHtml => UI::element('th', ['scope' => 'col', 'class' => 'px-4 py-3 font-semibold'], $heading),
                                ['Name', 'Email', 'Role', 'Status', ''],
                            )),
                        ]),
                        UI::element('tbody', [], $rows),
                    ]),
                ]),
            UI::element('div', ['class' => 'border-t border-slate-200 px-5 py-3 text-xs text-slate-500 dark:border-slate-800 dark:text-slate-400'], count($members) . ' people shown'),
            $this->inviteModal(),
        ]);
    }

    private function inviteModal(): SafeHtml
    {
        return UI::element('dialog', [
            'id' => 'invite-modal',
            'aria-labelledby' => 'invite-title',
            'class' => 'm-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/60 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-100',
            'data-nora-modal-state' => 'closed',
        ], [
            UI::element('div', ['class' => 'flex items-start justify-between border-b border-slate-200 p-6 dark:border-slate-800'], [
                UI::element('div', [], [
                    UI::element('h2', ['id' => 'invite-title', 'class' => 'text-lg font-semibold'], 'Invite a person'),
                    UI::element('p', ['class' => 'mt-1 text-sm text-slate-500 dark:text-slate-400'], 'They will receive access to this workspace.'),
                ]),
                UI::element('button', [
                    'type' => 'button',
                    'data-nora-modal-close' => true,
                    'aria-label' => 'Close invite dialog',
                    'class' => 'rounded-md p-1 text-slate-500 hover:bg-slate-100 dark:hover:bg-slate-800',
                ], '×'),
            ]),
            ReactiveForm::render(['action_name' => 'add-member', 'success_message' => 'Invitation added.'], UI::fragment([
                $this->textInput('name', 'Full name', 'Amina El Idrissi', 'text'),
                $this->textInput('email', 'Email address', 'name@example.com', 'email'),
                UI::element('label', ['class' => 'block text-sm font-medium'], [
                    UI::element('span', ['class' => 'mb-1.5 block'], 'Role'),
                    UI::element('select', [
                        'name' => 'role',
                        'data-nora-field' => 'role',
                        'class' => 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm dark:border-slate-700 dark:bg-slate-950',
                    ], [
                        UI::element('option', ['value' => 'Viewer'], 'Viewer'),
                        UI::element('option', ['value' => 'Editor'], 'Editor'),
                        UI::element('option', ['value' => 'Admin'], 'Admin'),
                    ]),
                ]),
                UI::element('div', ['class' => 'flex justify-end gap-3 pt-2'], [
                    UI::element('button', [
                        'type' => 'button',
                        'data-nora-modal-close' => true,
                        'class' => 'rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium hover:bg-slate-50 dark:border-slate-700 dark:hover:bg-slate-800',
                    ], 'Cancel'),
                    UI::element('button', [
                        'type' => 'submit',
                        'class' => 'rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700 disabled:cursor-not-allowed disabled:opacity-50',
                    ], 'Send invite'),
                ]),
            ]), ['class' => 'space-y-4 p-6 aria-busy:opacity-60']),
        ]);
    }

    private function profileForm(State $state): SafeHtml
    {
        return UI::element('section', [
            'id' => 'settings',
            'class' => 'grid gap-6 lg:grid-cols-[1.2fr_0.8fr]',
        ], [
            UI::element('article', ['class' => 'rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900'], [
                UI::element('h2', ['class' => 'text-lg font-semibold'], 'Profile settings'),
                UI::element('p', ['class' => 'mt-1 text-sm text-slate-500 dark:text-slate-400'], 'Update your account details.'),
                ReactiveForm::render(['action_name' => 'save-profile', 'success_message' => 'Your profile was saved.'], UI::fragment([
                    $this->textInput('name', 'Display name', 'Amina El Idrissi', 'text'),
                    $this->textInput('email', 'Email address', 'amina@example.test', 'email'),
                    UI::element('button', [
                        'type' => 'submit',
                        'class' => 'mt-2 rounded-lg bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 disabled:cursor-not-allowed disabled:opacity-50',
                    ], 'Save changes'),
                ]), ['class' => 'mt-5 space-y-4 aria-busy:opacity-60']),
                UI::when($state->get('profile_saved'), UI::element('p', [
                    'class' => 'mt-4 rounded-lg bg-emerald-50 px-4 py-3 text-sm text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
                    'role' => 'status',
                ], 'Your profile is up to date.')),
            ]),
            UI::element('article', ['class' => 'rounded-xl border border-slate-200 bg-white p-6 shadow-sm dark:border-slate-800 dark:bg-slate-900'], [
                UI::element('h2', ['class' => 'text-lg font-semibold'], 'Workspace health'),
                UI::element('p', ['class' => 'mt-1 text-sm text-slate-500 dark:text-slate-400'], 'Service status at a glance.'),
                UI::element('div', ['class' => 'mt-6 space-y-4'], [
                    $this->statusRow('API response', 'Healthy', 'bg-emerald-500'),
                    $this->statusRow('Background jobs', 'Operational', 'bg-emerald-500'),
                    $this->statusRow('Storage sync', 'Delayed', 'bg-amber-500'),
                ]),
                UI::element('div', ['class' => 'mt-5 rounded-lg bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950 dark:text-amber-200'], [
                    UI::element('strong', [], 'Attention: '),
                    'Your latest storage sync is taking longer than usual.',
                ]),
            ]),
        ]);
    }

    private function tabs(): SafeHtml
    {
        $tabClass = 'rounded-t-lg border-b-2 border-transparent px-4 py-3 text-sm font-medium text-slate-500 '
            . 'hover:text-slate-800 aria-selected:border-indigo-600 aria-selected:text-indigo-700 dark:text-slate-400 '
            . 'dark:hover:text-white dark:aria-selected:text-indigo-300';

        return UI::element('section', [
            'data-nora-tabs' => true,
            'class' => 'rounded-xl border border-slate-200 bg-white p-5 shadow-sm dark:border-slate-800 dark:bg-slate-900',
        ], [
            UI::element('h2', ['class' => 'text-lg font-semibold'], 'Workspace activity'),
            UI::element('div', ['role' => 'tablist', 'aria-label' => 'Workspace activity', 'class' => 'mt-4 flex gap-2 border-b border-slate-200 dark:border-slate-800'], [
                UI::element('button', [
                    'type' => 'button', 'role' => 'tab', 'id' => 'activity-tab-week', 'aria-controls' => 'activity-panel-week',
                    'aria-selected' => 'true', 'tabindex' => '0', 'data-nora-tab-target' => 'week', 'class' => $tabClass,
                ], 'This week'),
                UI::element('button', [
                    'type' => 'button', 'role' => 'tab', 'id' => 'activity-tab-month', 'aria-controls' => 'activity-panel-month',
                    'aria-selected' => 'false', 'tabindex' => '-1', 'data-nora-tab-target' => 'month', 'class' => $tabClass,
                ], 'This month'),
            ]),
            UI::element('div', [
                'id' => 'activity-panel-week', 'role' => 'tabpanel', 'aria-labelledby' => 'activity-tab-week',
                'data-nora-tab-panel' => 'week', 'tabindex' => '0', 'class' => 'py-4 text-sm text-slate-600 dark:text-slate-300',
            ], '12 people joined, 38 tasks were completed, and 4 projects shipped this week.'),
            UI::element('div', [
                'id' => 'activity-panel-month', 'role' => 'tabpanel', 'aria-labelledby' => 'activity-tab-month',
                'data-nora-tab-panel' => 'month', 'tabindex' => '0', 'hidden' => true,
                'class' => 'py-4 text-sm text-slate-600 dark:text-slate-300',
            ], 'This month: 146 tasks completed across 18 active projects.'),
        ]);
    }

    private function feedbackStates(State $state): SafeHtml
    {
        return UI::element('section', ['class' => 'grid gap-4 sm:grid-cols-3', 'aria-label' => 'Interface states'], [
            UI::element('article', ['class' => 'rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900'], [
                UI::element('p', ['class' => 'text-xs font-semibold uppercase tracking-wide text-slate-500'], 'Loading state'),
                UI::element('div', ['class' => 'mt-3 h-2 animate-pulse rounded-full bg-slate-200 dark:bg-slate-700', 'aria-label' => 'Loading preview']),
                UI::element('p', ['class' => 'mt-2 text-xs text-slate-500'], 'Refreshing recent activity'),
            ]),
            UI::element('article', ['class' => 'rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900'], [
                UI::element('p', ['class' => 'text-xs font-semibold uppercase tracking-wide text-slate-500'], 'Error state'),
                UI::element('p', ['class' => 'mt-3 text-sm text-rose-700 dark:text-rose-300', 'role' => 'alert'], 'Storage sync is delayed. No data was lost.'),
            ]),
            UI::element('article', ['class' => 'rounded-xl border border-slate-200 bg-white p-4 dark:border-slate-800 dark:bg-slate-900'], [
                UI::element('p', ['class' => 'text-xs font-semibold uppercase tracking-wide text-slate-500'], 'Reactive updates'),
                UI::element('p', ['class' => 'mt-3 text-2xl font-bold'], $state->get('refreshes')),
                UI::element('p', ['class' => 'text-xs text-slate-500'], 'Metric refresh actions'),
            ]),
        ]);
    }

    private function textInput(string $name, string $label, string $placeholder, string $type): SafeHtml
    {
        return UI::element('label', ['class' => 'block text-sm font-medium'], [
            UI::element('span', ['class' => 'mb-1.5 block'], $label),
            UI::element('input', [
                'type' => $type === 'email' ? 'text' : $type,
                'name' => $name,
                'placeholder' => $placeholder,
                'inputmode' => $type === 'email' ? 'email' : null,
                'autocomplete' => $name === 'email' ? 'email' : 'name',
                'required' => true,
                'data-nora-field' => $name,
                'class' => 'w-full rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm outline-none placeholder:text-slate-400 focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200 dark:border-slate-700 dark:bg-slate-950 dark:focus:ring-indigo-900',
            ]),
        ]);
    }

    private function statusRow(string $label, string $status, string $dotClass): SafeHtml
    {
        return UI::element('div', ['class' => 'flex items-center justify-between gap-3 text-sm'], [
            UI::element('span', ['class' => 'text-slate-600 dark:text-slate-300'], $label),
            UI::element('span', ['class' => 'inline-flex items-center gap-2 font-medium'], [
                UI::element('span', ['class' => 'size-2 rounded-full ' . $dotClass, 'aria-hidden' => true]),
                $status,
            ]),
        ]);
    }
}
