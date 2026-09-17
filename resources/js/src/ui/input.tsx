import * as React from 'react'

import { cn } from './utils'

export function Input({ className, ...props }: React.ComponentProps<'input'>) {
    return (
        <input
            data-slot="input"
            className={cn(
                'border-input placeholder:text-muted-foreground flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none transition-[color,box-shadow] disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                'focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]',
                'aria-invalid:ring-destructive/20 aria-invalid:border-destructive',
                className,
            )}
            {...props}
        />
    )
}
