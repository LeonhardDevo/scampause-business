export type BankDiffRow = {
    key: string;
    label: string;
    onRecord: string;
    inRequest: string;
    changed?: boolean;
    mono?: boolean;
    srOnRecord?: string;
    srInRequest?: string;
};

export type ScamKind = 'invoice' | 'vendor' | 'payroll' | 'ceo';

export type StoryStep = 'forward' | 'check' | 'approve';
