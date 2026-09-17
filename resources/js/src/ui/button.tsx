import * as React from 'react'

import { cn } from './utils'

type Variant = 'default' | 'secondary' | 'ghost' | 'outline'

const varianten: Record<Variant, string> = {
    default: 'bg-primary text-primary-foreground shadow-xs hover:bg-primary/90',
    secondary: 'bg-secondary text-secondary-foreground shadow-xs hover:bg-secondary/80',
    ghost: 'hover:bg-accent hover:text-accent-foreground',
    outline: 'border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground',
}

export function Button({
    className,
    variant = 'default',
    ...props
}: React.ComponentProps<'button'> & { variant?: Variant }) {
    return (
        <button
            data-slot="button"
            className={cn(
                "inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium whitespace-nowrap outline-none transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:size-4 [&_svg]:shrink-0",
                'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
                varianten[variant],
                className,
            )}
            {...props}
        />
    )
}
