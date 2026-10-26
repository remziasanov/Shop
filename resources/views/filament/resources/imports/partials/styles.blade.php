<style>
    .imp-steps { display: flex; flex-wrap: wrap; gap: .5rem; list-style: none; margin: 0; padding: 0; }
    .imp-step { display: flex; align-items: center; gap: .5rem; padding: .35rem .75rem; border-radius: 9999px; font-size: .875rem; background: rgba(var(--gray-500), .12); color: rgb(var(--gray-600)); }
    .imp-step-current { background: rgba(var(--primary-500), .18); color: rgb(var(--primary-600)); font-weight: 600; }
    .imp-step-done { background: rgba(var(--success-500), .16); color: rgb(var(--success-600)); }
    .imp-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(9.5rem, 1fr)); gap: 1rem; }
    .imp-stat { border-radius: .75rem; padding: .75rem 1rem; background: rgba(var(--gray-500), .08); }
    .imp-stat-value { font-size: 1.5rem; font-weight: 600; line-height: 1.2; }
    .imp-stat-label { font-size: .8rem; color: rgb(var(--gray-500)); }
    .imp-stat-danger .imp-stat-value { color: rgb(var(--danger-500)); }
    .imp-stat-warning .imp-stat-value { color: rgb(var(--warning-500)); }
    .imp-bar { width: 100%; height: .75rem; border-radius: 9999px; overflow: hidden; background: rgba(var(--gray-500), .2); }
    .imp-bar > div { height: 100%; background: rgb(var(--primary-500)); transition: width .4s ease; }
    .imp-scroll { overflow-x: auto; }
    .imp-table { width: 100%; border-collapse: collapse; font-size: .85rem; }
    .imp-table th, .imp-table td { padding: .4rem .6rem; text-align: start; vertical-align: top; border-bottom: 1px solid rgba(var(--gray-500), .2); }
    .imp-table th { font-weight: 600; white-space: nowrap; }
    .imp-cell-error { background: rgba(var(--danger-500), .16); color: rgb(var(--danger-500)); }
    .imp-cell-error small { display: block; font-size: .72rem; }
    .imp-cell-warning { background: rgba(var(--warning-500), .16); }
    .imp-muted { color: rgb(var(--gray-500)); }
    .imp-stack { display: flex; flex-direction: column; gap: 1rem; }
    .imp-row { display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; }
    .imp-note { border-radius: .75rem; padding: .75rem 1rem; font-size: .9rem; }
    .imp-note-warning { background: rgba(var(--warning-500), .14); }
    .imp-note-danger { background: rgba(var(--danger-500), .14); }
    .imp-note-info { background: rgba(var(--primary-500), .12); }
    .imp-sample { max-width: 14rem; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
</style>
