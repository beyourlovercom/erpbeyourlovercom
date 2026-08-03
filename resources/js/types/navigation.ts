export type ShellNavigationItem = {
    key: string
    label: string
    emoji?: string | null
    path: string
    route: string
    placeholder: boolean
    children: ShellNavigationItem[]
}

export type SafeUser = { id: number; name: string; email: string }

export type ShellPageProps = {
    auth: { user: SafeUser }
    shellNavigation: ShellNavigationItem[]
}
