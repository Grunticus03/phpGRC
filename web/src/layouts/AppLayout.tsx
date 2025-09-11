import { Outlet } from "react-router-dom";
import Nav from "../components/Nav";

export default function AppLayout(): JSX.Element {
  return (
    <>
      <Nav />
      <main className="container">
        <Outlet />
      </main>
    </>
  );
}
