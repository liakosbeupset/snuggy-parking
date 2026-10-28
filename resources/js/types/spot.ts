// The shape SpotPresenter serializes. Coordinates are decimal strings, as cast server-side.
export type Spot = {
    id: number;
    household_id: number;
    household_name: string;
    lat: string;
    lng: string;
    accuracy: string;
    note: string | null;
    has_photo: boolean;
    photo_url: string | null;
    parked_at: string;
    retrieved_at: string | null;
    parked_by: string | null;
    directions_url: string;
    is_active: boolean;
};
