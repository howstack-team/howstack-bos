import { createContext, useContext } from 'react'
import type { ConsoleThemeConfig } from '../../packages/console-theme-runtime'
export const ConsoleThemeContext = createContext<ConsoleThemeConfig | null>(null)
export const useConsoleTheme = () => useContext(ConsoleThemeContext)
