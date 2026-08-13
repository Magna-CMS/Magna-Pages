/**
 * The block document, as the server defines it.
 *
 * Mirrors docs/block-document-format.md. Nodes keep an index signature on
 * purpose: the format's tolerant-reader rule says unknown keys survive a
 * round trip, and a type that dropped them would quietly make the builder
 * the one editor that destroys future constructs.
 */

export interface BlockNode {
    id: string
    block: string
    settings?: Record<string, unknown>
    data?: Record<string, unknown>
    children?: BlockNode[]
    [key: string]: unknown
}

export interface ColumnNode {
    id: string
    span: number
    settings?: Record<string, unknown>
    blocks?: BlockNode[]
    [key: string]: unknown
}

export interface SectionNode {
    id: string
    type: 'section' | 'ref'
    settings?: Record<string, unknown>
    columns?: ColumnNode[]
    part?: string
    [key: string]: unknown
}

/** Legacy list form, or the wrapped `{schemaVersion, sections}` form. */
export type BlockDocument = SectionNode[] | WrappedDocument

export interface WrappedDocument {
    schemaVersion?: string
    sections: SectionNode[]
    [key: string]: unknown
}

export type PatchOp = 'add' | 'remove' | 'replace' | 'move'

export interface PatchOperation {
    op: PatchOp
    path: string
    value?: unknown
    from?: string
}

export interface BlockFieldDefinition {
    handle: string
    type: string
    label: string
    required: boolean
    default: unknown
    options: Record<string, string>
    multiple: boolean
    fields: BlockFieldDefinition[]
}

export interface BlockDefinition {
    handle: string
    label: string
    icon: string
    /**
     * Field handles this block exposes for editing on the canvas, in the
     * order it wants them offered. Absent means "the first eligible
     * field", which is what every block did before the declaration
     * existed — so no block.json has to change.
     */
    inlineFields?: string[]
    category: string
    requiresPermission: string | null
    fields: BlockFieldDefinition[]
}

export interface Capabilities {
    content: boolean
    structure: boolean
    style: boolean
    publish: boolean
}

export interface LockState {
    mine: boolean
    holder: { id: string; name: string } | null
    acquired_at?: string
}

export interface ApprovalState {
    id: string
    requested_at: string | null
}

/**
 * One style control, as the server describes it.
 *
 * The vocabulary is not mirrored here on purpose: the renderer decides
 * which keys mean anything, so it is the renderer that says what the
 * inspector may offer. A table in this file would be a second source of
 * truth, and the day the two disagreed an editor would set a value that
 * silently rendered as nothing.
 */
export interface StyleControl {
    key: string
    label: string
    control: 'text' | 'color' | 'select'
    group: string
    options: string[]
}

export type StyleControls = Record<string, StyleControl[]>

export interface BootstrapPayload {
    lock?: LockState
    approval?: ApprovalState | null
    bindingSources?: Record<string, string>
    styleControls?: StyleControls
    document: {
        id: string
        title: string
        slug: string
        path: string | null
        status: string
        updated_at: string | null
        blocks: BlockDocument
    }
    registry: BlockDefinition[]
    tokens: Record<string, string>
    capabilities: Capabilities
}

/** The sections of a document, whichever shape it arrived in. */
export function sectionsOf(document: BlockDocument): SectionNode[] {
    return Array.isArray(document) ? document : (document.sections ?? [])
}

/** The pointer prefix that addresses a document's sections. */
export function sectionsPointer(document: BlockDocument): string {
    return Array.isArray(document) ? '' : '/sections'
}
