export type User = {
    id: number;
    name: string;
    email: string;
    avatar: string | null;
};

export type Auth = {
    user: User | null;
};
