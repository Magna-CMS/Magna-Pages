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
    /**
     * Whether this block holds other blocks in `children`. Shipped by the
     * server rather than inferred from the handle, so a plugin's own
     * layout block is a drop target on exactly the same terms as core's.
     */
    container?: boolean
    /**
     * The data a freshly inserted instance starts with, computed from the
     * schema by the server. Seeding here instead would mean guessing what
     * an `optionsFrom` select offers on this installation, and inserting
     * blocks the save then refuses for a missing required field.
     */
    seed?: Record<string, unknown>
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
    /** `image` stores a URL and emits a url(); the server builds the
     *  function, so the control only collects the address. */
    control: 'text' | 'color' | 'select' | 'image'
    group: string
    options: string[]
}

export type StyleControls = Record<string, StyleControl[]>

/**
 * A show/hide rule this install can evaluate.
 *
 * Shipped by the server rather than listed here, so the picker can only ever
 * offer what the renderer will honour — a type it refuses hides the node,
 * which looks to whoever set it like the rule silently not working.
 */
export interface DisplayConditionOption {
    handle: string
    label: string
    /** `auth` and `schedule`, which have their own controls in the inspector. */
    builtIn: boolean
}

export interface ChromeChoice {
    id: string
    title: string
    slug: string
    /** Only a published part renders, so a draft is offered but not usable. */
    published: boolean
}

export interface BootstrapPayload {
    lock?: LockState
    approval?: ApprovalState | null
    bindingSources?: Record<string, string>
    styleControls?: StyleControls
    displayConditions?: DisplayConditionOption[]
    /** The server's icon vocabulary: name => inner SVG geometry. */
    icons?: Record<string, string>
    /** The headers and footers this page may choose between. */
    chrome?: { header: ChromeChoice[]; footer: ChromeChoice[] }
    document: {
        id: string
        title: string
        slug: string
        path: string | null
        status: string
        updated_at: string | null
        blocks: BlockDocument
        /** The page's own settings, stored beside the document not in it. */
        settings?: Record<string, unknown>
    }
    registry: BlockDefinition[]
    tokens: Record<string, string>
    capabilities: Capabilities
    /**
     * How deep blocks may nest, as the server counts it (a column-level
     * block is depth 1). Shipped rather than mirrored: a constant copied
     * into TypeScript is a second source of truth, and the day the two
     * disagree the builder either forbids a legal drop or offers one the
     * save will reject.
     */
    maxBlockDepth?: number
}

/** The sections of a document, whichever shape it arrived in. */
export function sectionsOf(document: BlockDocument): SectionNode[] {
    return Array.isArray(document) ? document : (document.sections ?? [])
}

/** The pointer prefix that addresses a document's sections. */
export function sectionsPointer(document: BlockDocument): string {
    return Array.isArray(document) ? '' : '/sections'
}
