export type DemoPolicyResult = {
    approvers: number;
    ownerRequired: boolean;
    callbackRequired: boolean;
    threshold: number;
};

export const DEMO_POLICY_BANDS: readonly DemoPolicyResult[] = [
    {
        threshold: 10000,
        approvers: 2,
        ownerRequired: true,
        callbackRequired: true,
    },
    {
        threshold: 3000,
        approvers: 2,
        ownerRequired: false,
        callbackRequired: true,
    },
    {
        threshold: 0,
        approvers: 1,
        ownerRequired: false,
        callbackRequired: false,
    },
] as const;

export function parseDemoAmount(input: string): number {
    const normalized = input.replace(/[\s ]/g, '').replace(',', '.');
    const value = Number.parseFloat(normalized);

    return Number.isFinite(value) && value > 0 ? value : 0;
}

export function simulateDemoPolicy(amount: number): DemoPolicyResult {
    const safe = Number.isFinite(amount) && amount > 0 ? amount : 0;

    return (
        DEMO_POLICY_BANDS.find((band) => safe >= band.threshold) ??
        DEMO_POLICY_BANDS[DEMO_POLICY_BANDS.length - 1]
    );
}
