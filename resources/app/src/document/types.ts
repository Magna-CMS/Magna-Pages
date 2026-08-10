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

export interface BootstrapPayload {
    lock?: LockState
    approval?: ApprovalState | null
    bindingSources?: Record<string, string>
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
